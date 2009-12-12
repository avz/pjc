<?php
/*
	$Id$
*/

require_once('XMLStream/XMLStream.class.php');
require_once('PJC_Log.class.php');
require_once('exceptions/PJC_NetworkException.class.php');
require_once('exceptions/PJC_NotAuthorizedException.class.php');

class PJC_XMPP {
	protected $host;
	protected $port;

	protected $username;
	protected $password;

	protected $resourceName;
	protected $realm;
	protected $priority;

	protected $sock;
	protected $handlers = array();

	protected $in;
	protected $out;

	protected $pingInterval = 180;
	protected $lastPingTime;

	protected $crontab = array();

	protected $useTls = true;

	protected $log = null;

	protected $initiated = false;

	function __construct($host, $port, $username, $password, $res = 'pjc', $priority = 1) {
		$this->initDefaultLogger();

		$this->lastPingTime = time();
		$this->connectionAddress = $host;
		if(preg_match('/^(.+?)@(.+)$/', $username, $m)) {
			$host = $m[2];
			$username = $m[1];
		}
		$this->host = $host;
		$this->port = $port;
		$this->username = $username;
		$this->password = $password;
		$this->resourceName = $res;
		$this->priority = $priority;
	}

	function __destruct() {
		if($this->sock)
			fclose($this->sock);
	}

	public function disableTls() {
		$this->useTls = false;
	}

	protected function connect() {
		$errno = 0;
		$errstr = 0;

		$this->sock = @fsockopen($this->connectionAddress, $this->port, $errno, $errstr);
		if(!$this->sock)
			throw new PJC_NetworkException($errstr, $errno);
		stream_set_blocking($this->sock, 0);

		$this->in = new XMLStream($this->sock);
		$this->out = new XMLStream($this->sock);
	}

	protected function startStream() {
		$this->out->write('<stream:stream xmlns="jabber:client" to="'.$this->host.'" version="1.0" xmlns:stream="http://etherx.jabber.org/streams">');
		$this->in->readNode('stream:stream');
		$this->in->readElement('stream:features');
		$this->log->notice('Stream started');
	}

	protected function startTls() {
		$this->sendStanza(array('#name'=>'starttls', 'xmlns'=>'urn:ietf:params:xml:ns:xmpp-tls'));
		$this->in->readElement('proceed');

		/*!TODO error handling */
		stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_SSLv23_CLIENT);
		$this->log->notice('TLS started');
	}

	protected function authorize() {
		$this->sendStanza(array(
			'#name'=>'auth',
			'xmlns'=>'urn:ietf:params:xml:ns:xmpp-sasl',
			'mechanism'=>'PLAIN',
			base64_encode("\x00".$this->username."\x00".$this->password)
		));

		$elt = $this->in->readElement();
		if($elt->getName() == 'failure')
			throw new PJC_NotAuthorizedException('Not authorized');
		elseif($elt->getName() != 'success')
			throw new PJC_NotAuthorizedException("Strange message:\n".$elt->dump());

		$this->log->notice('Authorized');
	}

	protected function genId() {
		static $lastId = 1;
		return sprintf('%u.%x%x%x', $lastId++, mt_rand(), mt_rand(), mt_rand()); // lol
	}

	protected function bindResource() {
		$this->sendIq('set', array(
			array(
				'#name'=>'bind',
				'xmlns'=>'urn:ietf:params:xml:ns:xmpp-bind',
				array(
					'#name'=>'resource',
					$this->resourceName
				)
			)
		));
		$elt = $this->in->readElement('iq');
		$this->realm = $elt->child('bind')->child('jid')->getText();
		$this->log->notice('Resource binded. JID: '.$this->realm);
	}

	function initiate() {
		$this->connect();
		$this->out->write('<?xml version="1.0"?>');
		$this->startStream();

		if($this->useTls) {
			$this->startTls();
			$this->startStream();
		}

		$this->authorize();
		$this->startStream();
		$this->bindResource();
		$this->sendIq('set', array(
			array(
				'#name'=>'session',
				'xmlns'=>'urn:ietf:params:xml:ns:xmpp-session'
			)
		));
		$this->in->readElement('iq');

		$this->presence();

		$this->initiated();
		$this->log->notice('Session initiated');
		$this->initiated = true;
	}

	protected function initiated() {
		$this->addHandler('iq:has(ping)', array($this, 'pingHandler'));

		$this->cronAddPeriodic($this->pingInterval, array($this, 'ping'));
	}

	function ping() {
		$this->lastPingTime = time();
		$this->sendIq('get', array(
			array('#name'=>'ping', 'xmlns'=>'urn:xmpp:ping')
		));

		$this->log->debug('Ping request');
	}

	public function addHandler($selector, $callback, $callbackParameters = array(), $extraPriority = false) {
		if($extraPriority) {
			if(!isset($this->handlers[$selector]))
				$this->handlers = array($selector=>array()) + $this->handlers;
		} else {
			if(!isset($this->handlers[$selector]))
				$this->handlers[$selector] = array();
		}

		$this->handlers[$selector][] = array('callback'=>$callback, 'params'=>$callbackParameters);
	}

	public function removeHandler($selector) {
		unset($this->handlers[$selector]);
	}

	public function runEventBased() {
		if(!$this->initiated)
			$this->initiate();
		$this->in->registerOnSelectCallback(array($this, 'alarm'));
		$this->updateCronAlarm();

		while(true) {
			$elt = $this->in->readElement();
			if(!$elt)
				break;
			if(!$this->runHandlers($elt))
				$this->log->debug('Unhandled event', $elt->dump());
		}
	}

	/* ----------------------------- cron ---------------------------- */

	public function alarm() {
		$this->cron();
		$this->updateCronAlarm();
	}

	public function cronAddPeriodic($interval, $callback, $callbackParameters = array(), $ident = null) {
		$rule = array('interval'=>$interval, 'callback'=>$callback, 'lastCall'=>time());
		$this->cronAddRule($interval > 0 ? $interval : 0, $callback, 'periodic', $callbackParameters, $ident);
	}

	public function cronAddOnce($timeout, $callback, $callbackParameters = array(), $ident = null) {
		$this->cronAddRule($timeout > 0 ? $timeout : 0, $callback, 'once', $callbackParameters, $ident);
	}

	protected function cronAddRule($time, $callback, $type, $callbackParameters = array(), $ident = null) {
		$rule = array(
			'type'=>$type,
			'time'=>$time,
			'callback'=>$callback,
			'callbackParameters'=>$callbackParameters,
			'lastCall'=>time(),
			'ident'=>$ident
		);
		$this->crontab[] = $rule;

		$this->cronSortRuleset();
		$this->updateCronAlarm();
	}

	public function cronHasRuleWithIdent($ident) {
		foreach($this->crontab as $ct)
			if($ct['ident'] === $ident)
				return true;
		return false;
	}

	public function cronRemoveRuleByIdent($ident) {
		$removed = 0;
		foreach($this->crontab as $k=>$ct) {
			if($ct['ident'] === $ident) {
				unset($this->crontab[$k]);
				$removed++;
			}
		}
		$this->cronSortRuleset();
		$this->log->debug("Removed $removed cron rules by ident '$ident'");
		$this->updateCronAlarm();
	}

	protected function cronSortRuleset() {
		usort($this->crontab, 'PJC_XMPP::cronQueueSortCb');
		$this->cronPrintRuleset();
	}
	protected function cronPrintRuleset() {
		$inf = '';
		foreach($this->crontab as $ct) {
			$delay = $ct['time'] - (time() - $ct['lastCall']);
			$inf .= ($ct['ident'] !== null ? "[{$ct['ident']}] " : '').(is_array($ct['callback']) ? get_class($ct['callback'][0])."::{$ct['callback'][1]}()" : $ct['callback'])." delay $delay ({$ct['type']})\n";
		}
		$this->log->debug('Crontab', $inf);
	}

	public static function cronQueueSortCb($a, $b) {
		$curTime = time();
		$at = $a['time'] - ($curTime - $a['lastCall']);
		$bt = $b['time'] - ($curTime - $b['lastCall']);

		if($at > $bt)
			return 1;
		elseif($at < $bt)
			return -1;
		else
			return 0;
	}

	protected function updateCronAlarm() {
		$timeout = null;
		while(($timeout = $this->cronGetVacationTime()) <= 0)
			$this->cron();

		$this->in->setSelectTimeout($timeout);
	}

	protected function cron() {
		while(sizeof($this->crontab)) {
			$ct = &$this->crontab[0];

			$curTime = time();
// 			var_dump($curTime - $ct['lastCall'], $ct['type']);
			if($curTime - $ct['lastCall'] >= $ct['time']) {
				switch($ct['type']) {
					case 'periodic':
						$ct['lastCall'] = $curTime;
						$this->crontab[] = array_shift($this->crontab);
						$this->cronSortRuleset();
					break;
					case 'once':
						array_shift($this->crontab);
					break;
					default:
						throw new Exception('Unknown crontab type `'.$ct['type'].'`');
					break;
				}

				call_user_func_array($ct['callback'], $ct['callbackParameters']);
			} else {
				break;
			}
		}
	}

	protected function cronGetVacationTime() {
		if(sizeof($this->crontab)) {
			$first = $this->crontab[0];
			$time = $first['time'] - (time() - $first['lastCall']);
		} else {
			$time = 24*3600;
		}
		$this->log->debug("Cron vacation time: $time");
		return $time > 0 ? $time : 0;
	}

	/* ---------------------------------- misc ----------------------------- */

	public function presence() {
		$this->sendStanza(array(
			'#name'=>'presence',
			array(
				'#name'=>'priority',
				$this->priority
			)
		));
	}

	protected function pingHandler($xmpp, $element) {
		if($element->hasChild('error')) {
			$err = $element->child('error');
			$errName = $err->firstChild()->getName();
			$this->log->debug('Ping response. Error #'.$err->getParam('code').' `'.$errName.'`');
		} else {
			$this->log->debug('Ping response');
		}
	}

	protected function runHandlers($element) {
		$handled = false;
		foreach($this->handlers as $sel=>$handlers) {
			if($this->testSelector($element, $sel)) {
				foreach($handlers as $hi) {
					$handled = true;
					if(call_user_func_array($hi['callback'], array_merge(array($this, $element), $hi['params'])) === false)
						break 2;
				}
			}
		}
		return $handled;
	}

	protected function testSelector($elt, $sel) {
		if($sel === '*')
			return true;
		if($elt->getName() === $sel)
			return true;
		elseif(preg_match('/^(.*?)(\[(.*?)\]|)(\:(.+)|)$/',$sel, $m)) {
			$name = $m[1];
			if($elt->getName() !== $name)
				return false;

			if(strlen($m[2])) {
				$paramsFilter = explode(',',$m[3]);
				$filters = array();
				foreach($paramsFilter as $pfs) {
					$f = explode('=', $pfs, 2);
					$paramName = $f[0];
					$paramValue = true;

					if(sizeof($f) == 2)
						$paramValue = $f[1];
					$filters[$paramName] = $paramValue;
				}

				foreach($filters as $n=>$v) {
					if(!$elt->hasParam($n))
						return false;
					$epv = $elt->getParam($n);
					if($v !== true && $epv !== $v)
						return false;
				}
			}

			if(strlen($m[4])) {
				$f = $m[5];
				if(preg_match('/^has\((.+)\)$/', $f, $m)) {
					$f = false;
					foreach($elt->childs() as $child) {
						if($this->testSelector($child, $m[1])) {
							$f = true;
							break;
						}
					}
					if(!$f)
						return false;
				} else {
					throw new Exception("Invalid selector `$sel`");
				}
			}
			return true;
		}
		return false;
	}

	public function shortJid() {
		return XMPP::parseJid($this->realm, 'short');
	}

	static function parseJid($jid, $component = null) {
		$arr = array();
		if(preg_match('/^([^@]+)@([^\/]+)(\/(.+)|)$/i', $jid, $m)) {
			$arr['username'] = $m[1];
			$arr['hostname'] = $m[2];
			$arr['short'] = $m[1].'@'.$m[2];
			if(strlen($m[3]) > 1)
				$arr['resource'] = $m[4];
			else
				$arr['resource'] = null;
		} else {
			return null;
		}

		if($component !== null) {
			assert(array_key_exists($component, $arr));
			return $arr[$component];
		}

		return $arr;
	}

	static function stanza(array $stanza) {
		if(!isset($stanza['#name']))
			throw new Exception('#name parameter not found');

		$elt = new XMLStreamElementMY($stanza['#name']);
		foreach($stanza as $p=>$v) {
			if(is_string($p)) {

				if($p === '#plainXML')
					$elt->appendPlainXML($v);
				elseif($p{0} === '#')
					continue;
				else
					$elt->appendParameter($p, $v);

			} elseif(is_int($p)) {
				if(is_array($v))
					$elt->appendChild(self::stanza($v));
				else
					$elt->appendTextNode($v);
			}
		}

		return $elt;
	}

	function sendStanza(array $stanza) {
		if(!isset($stanza['id']))
			$stanza['id'] = $this->genId();
		if(!isset($stanza['from']))
			$stanza['from'] = $this->realm;
// var_dump((string)self::stanza($stanza));
		$this->out->write((string)self::stanza($stanza));
		return $stanza['id'];
	}

	function send($xmlString) {
		$this->log->debug('Sending', $xmlString);
		$this->out->write((string)$xmlString);
	}

	function sendIq($type, $childs = array(), $id = null, $xmlns = 'jabber:client') {
		if($id===null)
			$id = $this->genId();

		$r = array(
			'#name'=>'iq',
			'xmlns'=>$xmlns,
			'type'=>$type,
		);
		$r = array_merge($r, $childs);
		$this->sendStanza($r);
	}

	function setLogger($logger) {
		$this->log = $logger;
	}

	function getLogger() {
		return $this->log;
	}

	function initDefaultLogger() {
		$this->setLogger(new PJC_Log);
	}
}
