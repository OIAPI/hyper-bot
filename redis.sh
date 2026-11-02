#!/bin/sh
### BEGIN INIT INFO
# Provides: redis-server
# Required-Start: $syslog $network
# Required-Stop: $syslog
# Should-Start: $local_fs
# Should-Stop: $local_fs
# Default-Start: 2 3 4 5
# Default-Stop: 0 1 6
# Short-Description: Start and stop the redis-server daemon
# Description: Redis persistent key - value database server
### END INIT INFO
REDIS_BIN=/usr/local/bin/redis-server
REDIS_CONFIG=/etc/redis/redis.conf
case "$1" in
start)
	$REDIS_BIN $REDIS_CONFIG
;;
stop)
	$REDIS_BIN -u /var/run/redis/redis.pid stop
;;
restart)
	$0 stop
	$0 start
;;
*)
echo "Please use start, stop, restart as argument"
exit 1
esac
exit 0
