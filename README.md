# mysql-rerep

This is a simple script that allows you to set up MySQL replication. It's called 'rerep' because it doesn't do some things needed for a first setup, but don't let that stop you.

## Usage

```
root@master# php rerep.php master /var/lib/mysql

root@slave# php rerep.php slave 10.0.0.2 /var/lib/mysql 0
```

`10.0.0.2` should be replaced by the hostname or IP of the master.

`/var/lib/mysql` should be the mysql data directory.

`0` should be a higher number to have your slave [deliberately lag behind](https://dev.mysql.com/doc/refman/5.7/en/replication-delayed.html).

## Assumptions

* We can connect as root via localhost to both servers with the same password.
* You already have mysql running on both servers.
* Some (write) downtime is acceptable as we hold a read-lock while copying data.
* You can rsync from the master to the slave as root (I recommend using pubkey authentication).
* `service mysql start|stop` starts/stops your MySQLd.
* You don't mind passwords visible in your terminal.
* The slave can connect to the master over TCP port 3306 and 4334.
* You love reading exceptions rather than nice error messages.
* You forgive me for writing an ugly PHP script.
