<?php
/**
 * This file is part of GameQ3.
 *
 * GameQ3 is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * GameQ3 is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 *
 */
 
namespace GameQ3;

class Sockets {
	private $log = null;
	
	// Settings
	private $connect_timeout = 1; // seconds
	private $send_once_udp = 5;
	private $send_once_stream = 5;
	private $usleep_udp = 100; // ns
	private $usleep_stream = 100; // ns
	private $read_timeout = 600;
	private $read_got_timeout = 20;
	private $read_retry_timeout = 200;
	private $loop_timeout = 2; // ms
	private $socket_buffer = 8192;
	private $send_retry = 1;
	
	// Work arrays
	private $cache_addr = array();
	private $sockets_udp = array();
	private $sockets_udp_data = array();
	private $sockets_udp_send = array();
	private $sockets_udp_sid = array();
	private $sockets_udp_socks = array();
	private $sockets_stream = array();
	private $sockets_stream_data = array();
	private $responses = array();
	private $send = array();
	private $recreated_udp = array(); // sctn => count
	private $recreated_stream = array(); // sid => count
	
	const SELECT_MAXTIMEOUT = 1; // ms
	
	public function __construct($log) {
		$this->log = $log;
	}
	
	public function setVar($key, $val) {
		if (!is_int($value))
			throw new GameQException("Value for setVar must be int. Got value: " . var_export($value, true));
			
		switch($key) {
			case 'connect_timeout': $this->connect_timeout = $value; break;
			case 'send_once_udp': $this->send_once_udp = $value; break;
			case 'send_once_stream': $this->send_once_stream = $value; break;
			case 'usleep_udp': $this->usleep_udp = $value; break; // ns
			case 'usleep_stream': $this->usleep_stream = $value; break; // ns
			case 'read_timeout': $this->read_timeout = $value; break;
			case 'read_retry_timeout': $this->read_retry_timeout = $value; break;
			case 'loop_timeout': $this->loop_timeout = $value; break; // ms
			case 'socket_buffer': $this->socket_buffer = $value; break;
			case 'send_retry': $this->send_retry = $value; break;
			case 'timeout': $this->timeout = $value; break;

			default:
				throw new GameQException("Unknown key in setSockOption: " . var_export($key, true));
		}
	}
	
	// Parse 'Addr:port' string
	private static function _parseHost($host) {
		$colonpos = strrpos($host, ':');
		if ($colonpos === false) {
			$server_addr = $host;
			$server_port = false;
		} else {
			$server_port = substr($host, $colonpos+1);
			if (!is_numeric($server_port)) {
				$server_addr = $host;
				$server_port = false;
			} else {
				$server_addr = substr($host, 0, $colonpos);
				$server_port = intval($server_port);
			}
		}
		
		return array($server_addr, $server_port);
	}
	
	// Parse server_info array and extract addresses
	public static function fillQueryConnectHosts($server_info) {
		// Check for server host
		if (empty($server_info['host']) && empty($server_info['addr'])) {
			throw new SocketsConfigException("Missing server info keys 'host' and 'addr'");
		}
		
		
		if (isset($server_info['addr'])) {
			$server_addr = $server_info['addr'];
			$server_port = (isset($server_info['port']) ? intval($server_info['port']) : false);
		} else {
			// Split addr and port
			list($server_addr, $server_port) = self::_parseHost($server_info['host']);
		}
		
		$connect_addr = $server_addr;
		$connect_port = $server_port;
		
		
		if (isset($server_info['connect_addr'])) {
			$connect_addr = $server_info['connect_addr'];
			if (isset($server_info['connect_port']))
				$connect_port = intval($server_info['connect_port']);
		} else if (isset($server_info['connect_host'])) {
			// Split addr and port
			list($connect_addr, $connect_port) = self::_parseHost($server_info['connect_host']);
		}
		

		if ($server_addr{0} == '[' && $server_addr{strlen($server_addr)-1} == ']') {
			if (!filter_var(substr($server_addr, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
				throw new SocketsConfigException("Wrong address (IPv6 filter failed): " . $server_addr);
		} else {
			if (!filter_var($server_addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
				if (!preg_match('/^[a-zA-Z0-9._-]{1,255}$/', $server_addr))
					throw new SocketsConfigException("Wrong address (IPv4 and hostname filters failed): " . $server_addr);
		}
		
		return array(
			$server_addr,
			$server_port,
			$connect_addr,
			$connect_port
		);
	}
	
	// Resolv address
	private function _resolveAddr($addr) {
		if (isset($this->cache_addr[$addr])) {
			return $this->cache_addr[$addr];
		}
		
		// Check for IPv6 format
		if ($addr{0} == '[' && $addr{strlen($addr)-1} == ']') {
			$t = substr($addr, 1, -1);
			if (!filter_var($t, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
				throw new SocketsConfigException("Wrong address (IPv6 filter failed) '" . $addr . "'");
			}
			$this->cache_addr[$addr] = array(AF_INET6, $t);
			return $this->cache_addr[$addr];
		}
		
		if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$this->cache_addr[$addr] = array(AF_INET, $addr);
			return $this->cache_addr[$addr];
		}
		
		// RFC1035
		//if (!preg_match('/^[a-zA-Z0-9.-]{1,255}$/', $addr)) return false;
		
		// Try faster gethostbyname
		$gh = gethostbyname($addr);
		if ($gh !== $addr) {
			// In case php guys add IPv6 support for this function in future versions.
			$r = false;
			if (filter_var($gh, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
				$r = array(AF_INET, $gh);
			} else
			if (filter_var($gh, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
				$r = array(AF_INET6, $gh);
			}
			if ($r !== false) {
				$this->cache_addr[$addr] = $r;
				return $r;
			}
		}
		
		// We failed, trying another way
		// We need this to pass timeout value to stream_socket_client/
		$errno = 0;
		$errstr = "";
		// Create UDP (connectionless) socket to our server on some random port.
		// Socket will not send any packet to the server, it will just resolve IP address
		$sock = @stream_socket_client("udp://" . $addr . ":30000", $errno, $errstr, 1);
		// If resolve failed socket returns false.
		if (!$sock)
			throw new SocketsException("Unable to resolv hostname '" . $addr . "'");
		// Extract addr:port
		$remote_addr = stream_socket_get_name($sock, true);
		// Free socket resource
		fclose($sock);
		// Cut off port
		if (!is_string($remote_addr) || strlen($remote_addr) <= 6 || substr($remote_addr, -6) != ':30000')
			throw new SocketsException("Unable to resolv hostname '" . $addr . "'");
		$remote_addr = substr($remote_addr, 0, -6);
		
		// Find out IP version
		$r = false;
		if (filter_var($remote_addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$r = array(AF_INET, $remote_addr);
		} else
		if (filter_var($remote_addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			$r = array(AF_INET6, $remote_addr);
		}
		if ($r !== false) {
			$this->cache_addr[$addr] = $r;
			return $r;
		}
		
		throw new SocketsException("Unable to resolv hostname '" . $addr . "'");
	}

	private function _createSocketUDP($sctn, $throw = false) {
		// This should never happen
		/*if (!isset($this->sockets_udp_data[$sctn]) {
			throw new GameQ_SocketsException("Cannot create UDP socket");
		}*/
		
		if (isset($this->sockets_udp[$sctn])) {
			$this->recreated_udp[$sctn] = true;
			if (is_resource($this->sockets_udp[$sctn])) {
				@socket_close($this->sockets_udp[$sctn]);
			}
			unset($this->sockets_udp[$sctn]);
		}
		
		$sock = socket_create($this->sockets_udp_data[$sctn], SOCK_DGRAM, SOL_UDP);
		if (!is_resource($sock)) {
			$errno = socket_last_error();
			$errstr = socket_strerror($errno);
			if ($throw) {
				throw new SocketsException("Cannot create socket for protocol 'udp'. Error[" . $errno. "]: " . $errstr);
			} else {
				$this->log->debug("Cannot create socket for protocol 'udp'. Error[" . $errno. "]: " . $errstr);
				return false;
			}
		}
		socket_set_nonblock($sock);
		//socket_bind($sock, ($this->sockets_udp_data[$sctn] === AF_INET ? '0.0.0.0' : '::0'));
		$this->sockets_udp[$sctn] = $sock;
		return true;
	}
	
	// Open socket
	private function _createSocketStream($sid, $throw = false) {
		// This should never happen
		/*if (!isset($this->sockets_stream_data[$sid]) {
			throw new GameQ_SocketsException("Cannot create stream socket");
		}*/
		
		if (isset($this->sockets_stream[$sid])) {
			$this->recreated_stream[$sid] = true;
			if (is_resource($this->sockets_stream[$sid])) {
				@fclose($this->sockets_stream[$sid]);
			}
			unset($this->sockets_stream[$sid]);
		}
		
		$proto = $this->sockets_stream_data[$sid]['pr'];
		$data = $this->sockets_stream_data[$sid]['d'];
		$port = $this->sockets_stream_data[$sid]['p'];
		
		// proto is already filtered here
		if ($proto == 'tcp') {
			$remote_addr = $proto . '://' . $data . ':' . $port;
		} else		
		if ($proto == 'unix' || $proto = 'udg') {
			$remote_addr = $proto . '://' . $data;
		}

		$errno = null;
		$errstr = null;
		// Create the socket
		$socket = @stream_socket_client($remote_addr, $errno, $errstr, $this->connect_timeout, STREAM_CLIENT_CONNECT);
		
		if (!is_resource($socket)) {
			if ($throw) {
				throw new SocketsException("Cannot create socket for address '". $remote_addr ."' Error[" . var_export($errno, true). "]: " . $errstr);
			} else {
				$this->log->debug("Cannot create socket for address '". $remote_addr ."' Error[" . var_export($errno, true). "]: " . $errstr);
				return false;
			}
		}
		
		stream_set_blocking($socket, false);
		stream_set_timeout($socket, $this->connect_timeout);
		
		$this->sockets_stream[$sid] = $socket;
		return true;
	}
	
	private function _writeSocketUDP($sid, &$packets) {
		if (!isset($this->sockets_udp_send[$sid])) return false;
		$sctn = $this->sockets_udp_send[$sid]['s'];
	
		// socket failed.
		if (!isset($this->sockets_udp[$sctn]) || !is_resource($this->sockets_udp[$sctn])) {
			// first fail
			if (isset($this->sockets_udp[$sctn]) && !is_resource($this->sockets_udp[$sctn])) {
				// failed to recreate socket
				if(!$this->_createSocketUDP($sctn)) {
					return false;
				}
			} else {
				return false;
			}
		}
		
		foreach($packets as &$packet) {
			socket_sendto($this->sockets_udp[$sctn], $packet, strlen($packet), 0 , $this->sockets_udp_send[$sid]['a'], $this->sockets_udp_send[$sid]['p']);
			usleep($this->usleep_udp);
		}
		
		// true means that socket is alive, not that packet had been successfully sent.
		// actually we don't have to check send for success
		return true;
	}
	
	
	private function _writeSocketStream($sid, &$packets, $retry = false) {
		// socket failed.
		if (!isset($this->sockets_stream[$sid]) || !is_resource($this->sockets_stream[$sid])) {
			// first fail
			if (isset($this->sockets_stream[$sid]) && !is_resource($this->sockets_stream[$sid])) {
				// failed to recreate socket
				if (!$this->_createSocketStream($sid)) {
					return false;
				}
			} else {
				return false;
			}
		}
		
		foreach($packets as &$packet) {
			$er = fwrite($this->sockets_stream[$sid], $packet);
			$er = ($er === false || $er <= 0 );
			if ($er) break;
			usleep($this->usleep_stream);
		}
		
		if ($er) {
			// Second fail. Very strange situation. Do not enter recursion, just return false for this packet.
			if ($retry) return false;
			if ($this->_createSocketStream($sid)) {
				$this->_writeSocketStream($sid, $packets, true);
			} else {
				return false;
			}
		}

		return true;
	}
	
	// Push request queue into class instance and prepare sockets
	public function allocateSocket($server_id, $queue_id, $queue_opts) {
		if (empty($queue_opts['transport']))
			throw new SocketsConfigException("Missing 'transport' key in allocateSocket() function");
		if (empty($queue_opts['packets']))
			throw new SocketsConfigException("Missing 'packets' key in allocateSocket() function");
			
		$proto = $queue_opts['transport'];
		$packs = $queue_opts['packets'];
		if (!is_array($packs))
			$packs = array($packs);
			
		if ($proto == 'udp' || $proto = 'tcp') {
			if (empty($queue_opts['addr']) || !is_string($queue_opts['addr']))
				throw new SocketsConfigException("Missing valid 'addr' key in allocateSocket() function");
			if (empty($queue_opts['port']) || !is_int($queue_opts['port']))
				throw new SocketsConfigException("Missing valid 'port' key in allocateSocket() function");
				
			// some domains may have multiple ip addreses. to avoid different addreses, resolved from one domain, we going to use resolved IPs in sid
			list($domain, $data) = $this->_resolveAddr($queue_opts['addr']);
			$domain_str = ($domain == AF_INET ? '4' : '6');
			$port = $queue_opts['port'];
			
		} else
		if ($proto == 'unix' || $proto = 'udg') {
			if (empty($queue_opts['path']) || !is_int($queue_opts['path']))
				throw new SocketsConfigException("Missing valid 'path' key in allocateSocket() function");
				
			$domain = false;
			$domain_str = 'u';
			$data = $queue_opts['path'];
			$port = false;
		} else {
			throw new SocketsConfigException("Unknown protocol '" . $proto . "'");
		}
	
	
		$sid = $server_id.':'.$queue_id.':'.$proto.':'.$domain_str.':'.$data.':'.($port !== false ? $port : "");
		if ($proto == 'udp') {
			// domain_str added to prevent mix of ipv4 and ipv6 (the chanse of this is about zero) sids in one sidnt
			$sidnt = $domain_str.':'.$data.':'.$port;
			/*
			// to find socket resource by its number. used below and for select.
			private $sockets_udp = array();
			number_of_socket => socket_resource
			
			// for sendto
			private $sockets_udp_send = array();
			sid => array( 's' => number_of_socket, 'a' => data, 'p' => port )
			
			// for recvfrom
			private $sockets_udp_sid = array();
			number_of_socket => array( domain:data:port => sid, ... )
			
			// for finding out first available socket. used below.
			private $sockets_udp_socks = array();
			domain:data:port => array( sid => number_of_socket, ...)
			
			// for socket recreation
			private $sockets_udp_data = array();
			number_of_socket => domain
			*/
			
			// Find number of socket to use. Separate IPv4 from IPv6.
			$sctn = $domain_str . ':';
			if (!isset($this->sockets_udp_socks[$sidnt])) {
				$this->sockets_udp_socks[$sidnt] = array();
				$sctn .= '0';
			} else {
				if (isset($this->sockets_udp_socks[$sidnt][$sid])) {
					$sctn = $this->sockets_udp_socks[$sidnt][$sid];
				} else {
					$sctn .= ''.count($this->sockets_udp_socks[$sidnt]);
				}
			}
			
			if (!isset($this->sockets_udp[$sctn])) {
				// Create socket
				$this->sockets_udp_sid[$sctn] = array();
				$this->sockets_udp_data[$sctn] = $domain;
				$this->_createSocketUDP($sctn, true);
			}
			$this->sockets_udp_sid[$sctn][$sidnt] = $sid;
			$this->sockets_udp_socks[$sidnt][$sid] = $sctn;
			$this->sockets_udp_send[$sid] = array(
				's' => $sctn, // we don't store socket resource here because socket can be recreated
				'a' => $data,
				'p' => $port
			);

			// We use single send array for all sockets because we want to send packets in order they come
			$this->send[$sid] = array(
				'u' => true, // is UDP
				'r' => (!isset($queue_opts['no_retry']) || $queue_opts['no_retry'] !== true), // retry send if no reply
				'p' => $packs, // packets to send
			);
		} else {
			/*
			// we are going to do all interesting work with this array
			private $sockets_stream = array();
			sid => socket_resource
			
			// for socket recreation
			private $sockets_stream_data = array();
			sid => array('pr' => proto, 'd' => data, 'p' => port)
			*/
			if (!isset($this->sockets_stream[$sid])) {
				if ($domain === AF_INET6)
					$data = '['.$data.']';
					
				$this->sockets_stream_data[$sid] = array(
					'pr' => $proto,
					'd' => $data,
					'p' => $port
				);
				$this->_createSocketStream($sid, true);
			}
			
			$this->send[$sid] = array(
				'u' => false,
				'r' => (isset($queue_opts['no_retry']) && $queue_opts['no_retry'] !== true),
				'p' => $packs,
			);
		}
	
		$this->responses[$sid] = array(
			'sr' => false,		// Socket recreated
			'p' => array(),		// Responses
			'pg' => null,		// Time between first sent packet and first got packet (ping)
			't' => 0,		// Extra tries
			'rc' => 0,		// Current responses count
			'mrc' => 		// Maximum responses count
				( (isset($queue_opts['response_count']) && is_int($queue_opts['response_count']))
				? $queue_opts['response_count'] : false),
			//'st' => 0,		// Microtime of last send
			'rt' => 0.		// Last receive time
		);
		return $sid;
	}
	
	private function _procCleanSockets() {
		while (true) {
			if (empty($this->sockets_stream)) break;
			$write = null;
			$except = $this->sockets_stream;
			$read = $this->sockets_stream;
			if (!stream_select($read, $write, $except, 0)) break;
			foreach($read as $sid => &$sock) {
				$buf = stream_socket_recvfrom($sock, $this->socket_buffer);
				if ($buf === false || strlen($buf) == 0) {
					$this->log->debug("Recreating stream socket. " . $sid);
					$this->_createSocketStream($sid);
				}
			}
			foreach($except as $sid => &$sock) {
				$this->log->debug("Recreating stream socket. " . $sid);
				$this->_createSocketStream($sid);
			}
		}

		while (true) {
			if (empty($this->sockets_udp)) break;
			$write = null;
			$except = $this->sockets_udp;
			$read = $this->sockets_udp;
			if (!socket_select($read, $write, $except, 0)) break;
			foreach($read as $sctn => &$sock) {
				$buf = "";
				$name = "";
				$port = 0;
				socket_recvfrom($sock, $buf, $this->socket_buffer, 0 , $name, $port );
				if ($buf === false || strlen($buf) == 0) {
					$this->log->debug("Recreating udp socket. " . $sctn);
					$this->_createSocketUDP($sctn);
				}
			}
			foreach($except as $sctn => &$sock) {
				$this->log->debug("Recreating udp socket. " . $sctn);
				$this->_createSocketUDP($sctn);
			}
		}
	}
	
	private function _procWrite(&$responses, &$read_udp, &$read_udp_sctn, &$read_udp_sid, &$read_stream) {
		// Send packets
		$s_udp_cnt = 0;
		$s_stream_cnt = 0;
		
		$long_to = true;
		
		foreach($this->send as $sid => $data) {
			$udp_limit = ($s_udp_cnt >= $this->send_once_udp);
			$stream_limit = ($s_stream_cnt >= $this->send_once_stream);
			
			if ($udp_limit && $stream_limit) {
				$long_to = false;
				break;
			}
			
			$is_udp = $data['u'];
			
			if (
				($is_udp && $udp_limit)
				|| (!$is_udp && $stream_limit)
			) {
				$long_to = false;
				continue;
			}

			$now = microtime(true);
			
			// packet already sent.
			if ($responses[$sid]['t'] !== 0) {
				// send just once?
				$timeout = ($responses[$sid]['t'] == 1 ? $this->read_timeout : $this->read_retry_timeout);
				
				// packet didn't timed out yet
				if (($now - $responses[$sid]['st'])*1000 < $timeout)
					continue;
					
				// packet timed out
				if ($responses[$sid]['t'] > $this->send_retry) {
					$this->log->debug("Packet timed out " . $sid);
					unset($this->send[$sid]);
					continue;
				}
			}
			
			if ($data['r'] !== true)
				unset($this->send[$sid]);
			
			if ($is_udp) {
				$r = $this->_writeSocketUDP($sid, $data['p']);
			} else {
				$r = $this->_writeSocketStream($sid, $data['p']);
			}

			if (!$r) {
				// don't read failed socket
				if ($is_udp && (!isset($this->sockets_udp[$sctn]) || !is_resource($this->sockets_udp[$sctn]))) {
					if (isset($this->sockets_udp_send[$sid]['s']))
						unset($read_udp[ $this->sockets_udp_send[$sid]['s'] ]);
				}
				continue;
			}
			
			$responses[$sid]['t']++;
			$responses[$sid]['st'] = $now;
			
			if ($is_udp) {
				$s_udp_cnt++;
				$sctn = $this->sockets_udp_send[$sid]['s'];
				// timeout identification
				// sid => socket_number
				$read_udp_sctn[$sid] = $sctn;
				// for select
				// socket_number => socket_resource
				$read_udp[$sctn] = $this->sockets_udp[$sctn];
				// for timeout identification
				// socket_number => array(sid => true, ...)
				// (are we waiting for a packet?)
				$read_udp_sid[$sctn][$sid] = true;
			} else {
				$s_stream_cnt++;
				// sid => socket_resource
				$read_stream[$sid] = $this->sockets_stream[$sid];
			}
		}
		
		return $long_to;
	}
	
	private function _procRead($start_time, $timeout, &$responses, &$read_udp, &$read_udp_sctn, &$read_udp_sid, &$read_stream) {
		foreach(array(true, false) as $is_udp) {
			$tio = (($start_time - microtime(true))*1000 + $timeout) * 1000;

			if ($is_udp && ($tio <= 0)) { // first loop
				return false;
			}
			
			$tio = max(0,min($tio, self::SELECT_MAXTIMEOUT*1000));
			
			$write = null; // we don't need to write
			if ($is_udp) {
				if (empty($read_udp)) continue;
				$except = $read_udp; // check for errors
				$read = $read_udp; // incoming packets
				$sr = @socket_select($read, $write, $except, 0, $tio);
			} else {
				if (empty($read_stream)) continue;
				$except = $read_stream; // check for errors
				$read = $read_stream; // incoming packets
				$sr = @stream_select($read, $write, $except, 0, $tio);
			}

			// as there can be much packets on a single socket, we are going to read them until we are done
			while (true) {
				if ($sr === false) {
					// nothing to scare
					break;
				} else
				if ($sr == 0) {
					// got no packets in latest select => we have nothing to do here
					break;
				} else
				if ($sr > 0) {

					$recv_time = microtime(true);
					foreach($read as $k => &$sock) {
						if ($is_udp) {
							$sctn = $k;
							
							$buf = "";
							$name = "";
							$port = 0;
							$res = socket_recvfrom($sock, $buf, $this->socket_buffer, 0 , $name, $port);

							$exception = ($res === false || $res <= 0 || strlen($buf) == 0);
						} else {
							$sid = $k;
							
							$buf = stream_socket_recvfrom($sock, $this->socket_buffer);

							// In winsock and unix sockets recv() returns empty string when
							// tcp connection is closed. I hope in this case too...
							$exception = ($buf === false || strlen($buf) == 0);
						}
						
						if ($exception) {
							$this->log->debug("Socket exception. " . $sid);

							// dont read broken socket. it will be recreated if wee still have something to send
							if ($is_udp) {
								unset($read_udp_sid[$sctn][$sid]);
								if (empty($read_udp_sid[$sctn])) {
									unset($read_udp[$sctn]);
								}
								unset($read_udp_sctn[$sid]);
							} else {
								unset($read_stream[$sid]);
							}
							continue;
						}

						if ($is_udp) {
							$sidnt = ($this->sockets_udp_data[$sctn] == AF_INET ? '4' : '6').':'.$name.':'.$port;
							// packet from unknown sender
							if (!isset($this->sockets_udp_sid[$sctn][$sidnt])) {
								$this->log->debug("Packet from unknown sender " . $name . ":" . $port);
								continue;
							}
								
							$sid = $this->sockets_udp_sid[$sctn][$sidnt];
							
							// if sid is already timed out
							if (!isset($read_udp_sctn[$sid])) {
								$this->log->debug("Received timed out sid " . $sid);
								continue;
							}
						}
							
						unset($this->send[$sid]);
						
						$responses[$sid]['rc']++;
						$responses[$sid]['rt'] = $recv_time;
						$responses[$sid]['p'] []= $buf;
						
						if ($responses[$sid]['pg'] === null) {
							$responses[$sid]['pg'] = ($recv_time - $responses[$sid]['st']);
						}
							
						if (($responses[$sid]['mrc'] > 0) && ($responses[$sid]['rc'] >= $responses[$sid]['mrc'])) {
							if ($is_udp) {
								unset($read_udp_sid[$sctn][$sid]);
								if (empty($read_udp_sid[$sctn])) {
									unset($read_udp[$sctn]);
								}
								unset($read_udp_sctn[$sid]);
							} else {
								unset($read_stream[$sid]);
							}
						}
					}
					
					foreach($except as $k => &$sock) {
						$this->log->debug("Socket exception. " . $k);
						if ($is_udp) {
							$sctn = $k;
							if (!$this->_createSocketUDP($sctn)) {
								$this->log->debug("Recreating udp socket. " . $sctn);
								// clean sids with that socket number
								foreach($read_udp_sid[$sctn] as $sid => $unused) {
									unset($read_udp_sid[$sctn][$sid]);
									unset($read_udp_sctn[$sid]);
								}
								unset($read_udp[$sctn]);
							}
						} else {
							$sid = $k;

							if (!$this->_createSocketStream($sid)) {
								$this->log->debug("Recreating stream socket. " . $sid);
								unset($read_stream[$sid]);
							}
						}
					}
				}
				
				$write = null; // we don't need to write
				if ($is_udp) {
					$except = $read_udp; // check for errors
					$read = $read_udp; // incoming packets
					$sr = @socket_select($read, $write, $except, 0);
				} else {
					$except = $read_stream; // check for errors
					$read = $read_stream; // incoming packets
					$sr = @stream_select($read, $write, $except, 0);
				}
			}
		}
		return true;
	}
	
	private function _procMarkTimedOut(&$responses, &$read_udp, &$read_udp_sctn, &$read_udp_sid, &$read_stream) {
		$now = microtime(true);
		foreach(array(true, false) as $is_udp) {
			if ($is_udp)
				$read_en =& $read_udp_sctn;
			else
				$read_en =& $read_stream;
				
			foreach($read_en as $sid => $val) {
				if ($responses[$sid]['rc'] === 0) {
					$timeout = ($responses[$sid]['t'] == 1 ? $this->read_timeout : $this->read_retry_timeout);
					$to = (($now - $responses[$sid]['st'])*1000 >= $timeout);
				} else {
					// some data received, count timeout another way
					$to = (($now - $responses[$sid]['rt'])*1000 >= $this->read_got_timeout);
				}
					
				// packet timed out
				if ($to) {
					if ($is_udp) {
						$sctn = $val;
						unset($read_udp_sid[$sctn][$sid]);
						if (empty($read_udp_sid[$sctn])) {
							unset($read_udp[$sctn]);
						}
						unset($read_udp_sctn[$sid]);
					} else {
						unset($read_stream[$sid]);
					}
				}

			}
		}
	}
	
	public function process() {

		$responses = &$this->responses;
		unset($this->responses);

		// Reset sockets as we could get something while we didn't waited for data. We don't need it.
		$this->_procCleanSockets();

		// empty var for passing by reference
		$n = null;
		// For select

				
		// Actual list of udp sockets resourses. sctn => socket_resource.
		$read_udp = array();
		// When sid times out, we need to unset $read_udp_sid[$sctn][$sid]. sid => sctn
		$read_udp_sctn = array();
		// List of sids per socket. We need this to know when should we stop listening that socket. sctn => array( sid => true, ...)
		$read_udp_sid = array();
		// Actual list of stream sockets resourses. sid => socket_resourse
		$read_stream = array();
// \/
		while (true) {
		/*
			The logic is pretty simple: send small amount of packets and then wait for data
			a little bit of time. Such alghoritm lets us to send very much packets.
			The only limitation is memory.
			
			We can't select both streams and sockets at once, so we have to choose somthing
			that will be the first. Sockets will receive much more packets than streams,
			so they are more important.
		*/
			if (empty($this->send)) break;

			// Send packets
			if ($this->_procWrite($responses, $read_udp, $read_udp_sctn, $read_udp_sid, $read_stream)) {
				$timeout = max($this->read_timeout, $this->read_retry_timeout);
			} else {
				$timeout = $this->loop_timeout;
			}

			$start_time = microtime(true);

			while (true) {
				if (empty($read_udp) && empty($read_stream)) {
					break;
				}

				$this->_procMarkTimedOut($responses, $read_udp, $read_udp_sctn, $read_udp_sid, $read_stream);
				if (!$this->_procRead($start_time, $timeout, $responses, $read_udp, $read_udp_sctn, $read_udp_sid, $read_stream))
					break;

			}
		}
// /\ memory allocated 36943/5000=7.4 kb , 373/50=7.4 kb
// $responses size 12761/5000=2.55 kb, 129/50=2.58 kb
// 1.3 kb freed after closing sockets

		// Mark recreated sockets
		
		foreach($this->recreated_stream as $sid => $r) {
			$responses[$sid]['sr'] = true;
		}
		
		foreach($this->recreated_udp as $sctn => $r) {
			foreach($this->sockets_udp_sid[$sctn] as $sid) {
				$responses[$sid]['sr'] = true;
			}
		}

		// Cleanup

		$this->responses = array();
		$this->send = array();
		$this->recreated_udp = array();
		$this->recreated_stream = array();

		return $responses;
	}
	
	public function cleanUp() {
		foreach($this->sockets_udp as $snum => &$sock) {
			if (is_resource($this->sockets_udp[$snum]))
				@socket_close($this->sockets_udp[$snum]);
		}
		
		foreach($this->sockets_stream as $sid => &$sock) {
			if (is_resource($this->sockets_stream[$sid]))
				@fclose($this->sockets_stream[$sid]);
		}
		
		$this->cache_addr = array();
		$this->sockets_udp = array();
		$this->sockets_udp_data = array();
		$this->sockets_udp_send = array();
		$this->sockets_udp_sid = array();
		$this->sockets_udp_socks = array();
		$this->sockets_stream = array();
		$this->sockets_stream_data = array();
		$this->responses = array();
		$this->send = array();	
		$this->recreated_udp = array();
		$this->recreated_stream = array();
	}
}

class SocketsConfigException extends \Exception {} // Various configuration errors
class SocketsException extends \Exception {} // Various socket errors
