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

	$mode = $_SERVER['argv'][1];
	switch($mode) {
		case 'master':
			$master = true;
			$datadir = $_SERVER['argv'][2];
			break;
		case 'slave':
			$master = false;
			$masterhost = $_SERVER['argv'][2];
			$datadir = $_SERVER['argv'][3];
			$delay = intval($_SERVER['argv'][4]);
			break;
		default:
			qassert(false, 'mode must be master or slave');
	}

	$datadir = rtrim($datadir, '/');
	qassert(is_dir($datadir), 'datadir does not exist');
	qassert(is_dir($datadir .'/mysql'), 'datadir doesn\'t look like a mysql datadir');
	qassert(is_file($datadir .'/mysql/user.frm'), 'datadir doesn\'t look like a mysql datadir');

	if($master) {
		$lsock = socket_create_listen(4336);
		echo "Waiting for rerep slave to connect...";
		flush();
		$sock = socket_accept($lsock);
		echo "\n";
		socket_close($lsock);
		qassert(fetch_line() == 'MySQL rereplicator v1.1', 'version mismatch');
		send_line(md5_file(__FILE__));
	} else {
		$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		socket_connect($sock, $masterhost, 4336);
		send_line('MySQL rereplicator v1.1');
		qassert(fetch_line() == md5_file(__FILE__), 'strict version mismatch');
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

	// [run on true=master/false=slave, callback, result variable, extra args...]
	$stages = [
		[true, "ask_rootpass", 'root_password'],
		[true, "verify_mysql_connect", ''],
		[false, "verify_mysql_connect", ''],
		[false, "generate_username", 'repl_username'],
		[false, "generate_password", 'repl_password'],
		[false, "exchange_datadir", 'slave_datadir'],
		[false, "ask_confirmation", ''],
		[true, "ask_confirmation", ''],
		[false, "reset_slave", ''],
		[false, "startstop_mysqld", '', false],
		[true, "rsync", ''],
		[true, "ask_confirmation", ''],
		[true, "rsync", ''],
		[true, "lock_tables", ''],
		[true, "get_master_pos", 'master_info'],
		[true, "rsync", ''],
		[true, "unlock_tables", ''],
		[true, "ask_confirmation", ''],
		[true, "create_repl_user", ''],
		[true, "get_master_pos", 'master_info2'],
		[false, "startstop_mysqld", '', true],
		[false, "configure_slave", ''],
		[false, "wait_for_catch_up", ''],
		[false, "configure_slave_delay", ''],
	];

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

	function reset_slave() {
		$m = open_mysql();
		do_query($m, 'STOP SLAVE');
		do_query($m, 'RESET SLAVE');
		$m->close();
	}

	function startstop_mysqld($on) {
		$what = $on ? 'start' : 'stop';
		passthru("service mysql $what", $ret);
		qassert($ret == 0, "exit code $ret");
	}

	function rsync() {
		global $peer, $stage_output, $datadir;
		$localdir = $datadir;
		$remotedir = $stage_output['slave_datadir'];
		$exclude = ['relay-log.info', 'master.info', 'auto.cnf'];
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
		$cmd .= ' '. $localdir .'/ root@'. $peer .':'. $remotedir .'/';
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

	function configure_slave_delay() {
		global $delay;
		$m = open_mysql();
		do_query($m, "STOP SLAVE");
		do_query($m, "CHANGE MASTER TO MASTER_DELAY=". $delay);
		do_query($m, "START SLAVE");
		$m->close();
	}
?>
