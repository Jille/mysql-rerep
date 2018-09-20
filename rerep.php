<?php
	// (c) Jille Timmermans, 2017

	function exception_error_handler($severity, $message, $file, $line) {
		throw new ErrorException($message, 0, $severity, $file, $line);
	}
	set_error_handler("exception_error_handler");
	error_reporting(E_ALL);
	function qassert($ok, $msg='') {
		if(!$ok) {
			throw new Exception('Assertion failed' . ($msg ? ': '. $msg : ''));
		}
	}

	qassert(getmyuid() == 0, 'must run as root');

	$flags = [
		// name => [master=true/slave=false, hasValue, cast callback]
		'slave-is-down' => [false, false, NULL],
		'slave-delay' => [false, true, 'intval'],
		'use-tmpdir' => [true, false, NULL],
		'reuse-tmpdir' => [true, true, 'strval'],
		'noconfirm' => [false, false, NULL],
	];

	function parse_flags() {
		global $flags;
		$longopts = [];
		foreach($flags as $name => list(, $hasValue)) {
			$fn = $name;
			if($hasValue) {
				$fn .= ':';
			}
			array_push($longopts, $fn);
		}

		// I had to implement my own getopt because PHP's getopt() only has $optind from 7.1. It sucks. Don't reuse it.
		$args = [$_SERVER['argv'][0]];
		$parsed = [];
		$unprocessed = array_slice($_SERVER['argv'], 1);
		while($unprocessed) {
			$arg = array_shift($unprocessed);
			if(!$arg || substr($arg, 0, 2) != '--') {
				array_unshift($unprocessed, $arg);
				break;
			}
			$ex = explode('=', substr($arg, 2), 2);
			$k = $ex[0];
			qassert(isset($flags[$k]), 'Flag '. $k .' does not exist');
			if(count($ex) == 2) {
				$v = $ex[1];
				qassert($flags[$k][1], 'Flag '. $k .' takes no value');
			} else {
				if($flags[$k][1]) {
					$v = array_shift($unprocessed);
				} else {
					$v = true;
				}
			}
			$parsed[$k] = $v;
		}
		$args = array_merge($args, $unprocessed);

		$opts = [];
		$per_type = [true => [], false => []];
		foreach($flags as $name => list($type, $hasValue, $caster)) {
			if(isset($parsed[$name])) {
				$opts[$name] = $hasValue ? $caster($parsed[$name]) : true;
				array_push($per_type[$type], $name);
			} else {
				$opts[$name] = false;
			}
		}

		return [$opts, $args, $per_type[true], $per_type[false]];
	}

	function merge_opts($master, $slave) {
		global $flags;
		$ret = [];
		foreach($flags as $name => list($type)) {
			if($type) {
				$ret[$name] = $master[$name];
			} else {
				$ret[$name] = $slave[$name];
			}
		}
		return $ret;
	}

	list($opts, $args, $masterFlags, $slaveFlags) = parse_flags();

	$mode = $args[1];
	switch($mode) {
		case 'master':
			qassert(count($args) == 3, 'Usage: php rerep.php [--flags] master <datadir>');
			$master = true;
			$datadir = $args[2];
			qassert(count($slaveFlags) == 0, implode(', ', $slaveFlags) .' can only be set on the slave side');
			break;
		case 'slave':
			qassert(count($args) == 4, 'Usage: php rerep.php [--flags] slave <master-host> <datadir> <delay>');
			$master = false;
			$masterhost = $args[2];
			$datadir = $args[3];
			qassert(count($masterFlags) == 0, implode(', ', $masterFlags) .' can only be set on the master side');
			break;
		default:
			qassert(false, 'mode must be master or slave');
	}

	$datadir = rtrim($datadir, '/');
	qassert(is_dir($datadir), 'datadir does not exist');
	qassert(is_dir($datadir .'/mysql'), 'datadir doesn\'t look like a mysql datadir');
	qassert(is_file($datadir .'/mysql/user.frm'), 'datadir doesn\'t look like a mysql datadir');

	if($opts['reuse-tmpdir']) {
		$tmpdir = $opts['reuse-tmpdir'];
	} elseif($opts['use-tmpdir']) {
		$tmpdir = '/tmp/rerep-'. getmypid();
	} else {
		$tmpdir = NULL;
	}

	if($master) {
		$lsock = socket_create_listen(4336);
		echo "Waiting for rerep slave to connect...";
		flush();
		$sock = socket_accept($lsock);
		echo "\n";
		socket_close($lsock);
		qassert(fetch_line() == 'MySQL rereplicator v2.1', 'version mismatch');
		send_line(md5_file(__FILE__));

		send_line(base64_encode(json_encode($opts)));
		$remoteOpts = json_decode(base64_decode(fetch_line()), true);
		$opts = merge_opts($opts, $remoteOpts);
	} else {
		$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		socket_connect($sock, $masterhost, 4336);
		send_line('MySQL rereplicator v2.1');
		qassert(fetch_line() == md5_file(__FILE__), 'strict version mismatch');

		$remoteOpts = json_decode(base64_decode(fetch_line()), true);
		send_line(base64_encode(json_encode($opts)));
		$opts = merge_opts($remoteOpts, $opts);
	}

	$peer = 'unknown';
	qassert(socket_getpeername($sock, $peer), 'socket_getpeername failed');
	qassert($peer != 'unknown', 'failed to get peer');
	echo "You're connected to $peer\n";

	function fetch_line() {
		global $sock;
		$data = socket_read($sock, 4096, PHP_NORMAL_READ);
		qassert(!!$data, 'failed to read from socket');
		return rtrim($data);
	}

	function send_line($line) {
		global $sock;
		socket_write($sock, $line ."\n");
	}

	function get_stages($opts) {
		// [run on true=master/false=slave, callback, result variable, extra args...]
		$stages = [];
		array_push($stages, [true, "ask_rootpass", 'root_password']);
		array_push($stages, [true, "verify_mysql_connect", '']);
		if(!$opts['slave-is-down']) {
			array_push($stages, [false, "verify_mysql_connect", '']);
		}
		array_push($stages, [false, "generate_username", 'repl_username']);
		array_push($stages, [false, "generate_password", 'repl_password']);
		array_push($stages, [false, "exchange_datadir", 'slave_datadir']);
		if(!$opts['noconfirm']) {
			array_push($stages, [false, "ask_confirmation", '']);
			array_push($stages, [true, "ask_confirmation", '']);
		}
		if(!$opts['slave-is-down']) {
			array_push($stages, [false, "reset_slave", '', true]);
			array_push($stages, [false, "startstop_mysqld", '', false]);
		} else {
			array_push($stages, [false, "reset_slave", '', false]);
		}
		if(!$opts['reuse-tmpdir']) {
			$rsyncTarget = $opts['use-tmpdir'] ? 'tmp' : 'remote';
			array_push($stages, [true, "rsync", '', 'local', $rsyncTarget]);
			if(!$opts['noconfirm']) {
				array_push($stages, [true, "ask_confirmation", '']);
			}
			array_push($stages, [true, "rsync", '', 'local', $rsyncTarget]);
			array_push($stages, [true, "lock_tables", '']);
			array_push($stages, [true, "get_master_pos", 'master_info']);
			array_push($stages, [true, "rsync", '', 'local', $rsyncTarget]);
			array_push($stages, [true, "unlock_tables", '']);
			if($opts['use-tmpdir']) {
				array_push($stages, [true, "store_master_info", '']);
			}
			if(!$opts['noconfirm']) {
				array_push($stages, [true, "ask_confirmation", '']);
			}
		} else {
			array_push($stages, [true, "load_master_info", 'master_info']);
		}
		array_push($stages, [true, "create_repl_user", '']);
		array_push($stages, [true, "get_master_pos", 'master_info2']);
		if($opts['use-tmpdir'] || $opts['reuse-tmpdir']) {
			array_push($stages, [true, "rsync", '', 'tmp', 'remote']);
		}
		array_push($stages, [false, "startstop_mysqld", '', true]);
		array_push($stages, [false, "configure_slave", '']);
		array_push($stages, [false, "wait_for_catch_up", '']);
		if($opts['slave-delay']) {
			array_push($stages, [false, "configure_slave_delay", '', $opts['slave-delay']]);
		}

		return $stages;
	}

	$stages = get_stages($opts);

	$stage_output = [];
	foreach($stages as $i => $stage) {
		if($stage[0] != $master) {
			echo "Waiting for remote to complete ". $stage[1] ."...";
			flush();
			$line = fetch_line();
			qassert(fetch_line() == "COMPLETED $i");
			echo " $line\n";
			if($stage[2]) {
				$stage_output[$stage[2]] = $line;
			}
			continue;
		}

		echo "Running ". $stage[1] ."\n";
		$t = microtime(true);
		$ret = call_user_func_array($stage[1], array_slice($stage, 3));
		$d = microtime(true) - $t;
		send_line($ret);
		send_line("COMPLETED $i");
		if($stage[2]) {
			$stage_output[$stage[2]] = $ret;
		}
		echo "Finished ". $stage[1] ." in ". round($d, 2) ."s\n";
	}
	socket_close($sock);
	echo "Done!\n";

	if($tmpdir) {
		echo "Don't forget to remove the tempdir manually: ". $tmpdir ."\n";
	}

	function ask_rootpass() {
		echo "Please enter your MySQL root password. Note it will show up in plain text in both terminals.\n";
		echo "Password: ";
		flush();
		return rtrim(fread(STDIN, 1024));
	}

	function verify_mysql_connect() {
		open_mysql()->close();
	}

	function generate_username() {
		return 'repl_'. explode('.', gethostname())[0];
	}

	function generate_password() {
		return substr(base64_encode(openssl_random_pseudo_bytes(30)), 0, 32);
	}

	function exchange_datadir() {
		global $datadir;
		return $datadir;
	}

	function ask_confirmation() {
		echo "Are you sure you want to continue? Press enter or ^C\n";
		fread(STDIN, 64);
	}

	function open_mysql() {
		global $stage_output;
		$m = new MySQLi('localhost', 'root', $stage_output['root_password']);
		qassert(!$m->connect_error, 'failed to connect to localhost: '. $m->connect_error);
		return $m;
	}

	function do_query($m, $sql) {
		$ret = $m->query($sql);
		qassert($ret, $sql .' failed: '. mysqli_error($m));
		return $ret;
	}

	function reset_slave($running) {
		global $datadir;
		if($running) {
			$m = open_mysql();
			do_query($m, 'STOP SLAVE');
			do_query($m, 'RESET SLAVE');
			$m->close();
		} else {
			$fn = $datadir .'/master.info';
			if(is_file($fn)) {
				unlink($datadir .'/master.info');
			}
		}
	}

	function startstop_mysqld($on) {
		$what = $on ? 'start' : 'stop';
		passthru("service mysql $what", $ret);
		qassert($ret == 0, "exit code $ret");
	}

	function rsync($from, $to) {
		global $peer, $stage_output, $datadir, $tmpdir;
		$lookup = [
			'tmp' => $tmpdir,
			'local' => $datadir,
			'remote' => 'root@'. $peer .':'. $stage_output['slave_datadir'],
		];
		$localdir = $lookup[$from];
		$remotedir = $lookup[$to];
		qassert($localdir, '$localdir is unset?');
		qassert($remotedir, '$remotedir is unset?');
		$exclude = ['relay-log.info', 'master.info', 'auto.cnf', 'rerep.info'];
		foreach(glob($localdir .'/*.index') as $idx) {
			$logname = basename($idx, '.index');
			$logs = glob($localdir .'/'. $logname .'.[0-9]*');
			array_splice($logs, -1);
			foreach($logs as $log) {
				$exclude[] = basename($log);
			}
		}

		$cmd = 'rsync -SaP --delete';
		foreach($exclude as $fn) {
			$cmd .= ' --exclude='. $fn;
		}
		$cmd .= ' '. $localdir .'/ '. $remotedir .'/';
		passthru($cmd, $ret);
		qassert($ret == 0, "exit code $ret");
	}

	function lock_tables() {
		global $locked_m;
		$locked_m = open_mysql();
		do_query($locked_m, 'FLUSH TABLES WITH READ LOCK');
	}

	function get_master_pos() {
		$m = open_mysql();
		$res = do_query($m, 'SHOW MASTER STATUS');
		$masterState = $res->fetch_object();
		$m->close();
		return $masterState->File .' '. $masterState->Position;
	}

	function unlock_tables() {
		global $locked_m;
		do_query($locked_m, 'UNLOCK TABLES');
		$locked_m->close();
	}

	function store_master_info() {
		global $stage_output, $tmpdir;
		file_put_contents($tmpdir .'/rerep.info', $stage_output['master_info'] ."\n");
	}

	function load_master_info() {
		global $tmpdir;
		return trim(file_get_contents($tmpdir .'/rerep.info'));
	}

	function create_repl_user() {
		global $stage_output, $peer;
		$m = open_mysql();
		$user = "'". $stage_output['repl_username'] ."'@'". $peer ."'";
		do_query($m, 'DROP USER IF EXISTS '. $user);
		do_query($m, "CREATE USER ". $user ." IDENTIFIED BY '". $stage_output['repl_password'] ."'");
		do_query($m, "GRANT REPLICATION SLAVE ON *.* TO ". $user);
		$m->close();
	}

	function configure_slave() {
		global $masterhost, $stage_output;
		$m = open_mysql();
		list($log, $pos) = explode(' ', $stage_output['master_info']);
		do_query($m, "CHANGE MASTER TO
			MASTER_HOST='$masterhost',
			MASTER_USER='". $stage_output['repl_username'] ."',
			MASTER_PASSWORD='". $stage_output['repl_password'] ."',
			MASTER_LOG_FILE='". $log ."',
			MASTER_LOG_POS=". $pos .";");
		do_query($m, "START SLAVE");
		$m->close();
	}

	function wait_for_catch_up() {
		global $stage_output;
		$m = open_mysql();
		list($log, $pos) = explode(' ', $stage_output['master_info2']);
		$res = do_query($m, 'SELECT MASTER_POS_WAIT("'. $log .'", '. $pos .', 300)');
		$masterWait = $res->fetch_row();
		qassert($masterWait[0] !== NULL, 'replication has failed');
		qassert($masterWait[0] >= 0, 'replication didn\'t catch up within five minutes');
		$m->close();
	}

	function configure_slave_delay($delay) {
		$m = open_mysql();
		do_query($m, "STOP SLAVE");
		do_query($m, "CHANGE MASTER TO MASTER_DELAY=". $delay);
		do_query($m, "START SLAVE");
		$m->close();
	}
?>
