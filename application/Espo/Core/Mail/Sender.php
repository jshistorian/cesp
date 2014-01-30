<?php

namespace Espo\Core\Mail;

use \Espo\Entities\Email;

use Zend\Mime\Message as MimeMessage;
use Zend\Mime\Part as MimePart;
use Zend\Mime\Mime as Mime;

use Zend\Mail\Message;
use Zend\Mail\Transport\Smtp as SmtpTransport;
use Zend\Mail\Transport\SmtpOptions;

use \Espo\Core\Exceptions\Error;

class Sender
{
	protected $config;

	protected $transport;
	
	protected $isGlobal = false;
	
	protected $params = array();

	public function __construct($config)
	{
		$this->config = $config;		
		$this->useGlobal();
	}
	
	public function resetParams()
	{
		$this->params = array();
		return $this;
	}
	
	public function setParams(array $params = array())
	{
		$this->params = array_merge($this->params, $params);
		return $this;
	}
	
	public function useSmtp(array $params = array())
	{		
		$this->isGlobal = false;		
		$this->params = $params;
		
		$this->transport = new SmtpTransport();
		
		$opts = array(
			'name' => 'admin',
			'host' => $params['server'],
			'port' => $params['port'],
			'connection_config' => array()
		);
		if ($params['auth']) {
			$opts['connection_class'] = 'login';
			$opts['connection_config']['username'] = $params['username'];
			$opts['connection_config']['password'] = $params['password'];
		}
		if ($params['security']) {
			$opts['connection_config']['ssl'] = strtolower($params['security']);
		}

		$options = new SmtpOptions($opts);
		$this->transport->setOptions($options);
				
		return $this;
	}

	public function useGlobal()
	{
		$this->params = array();
		if ($this->isGlobal) {
			return $this;
		}
		
		$this->transport = new SmtpTransport();
		
		$config = $this->config;

		$opts = array(
			'name' => 'admin',
			'host' => $config->get('smtpServer'),
			'port' => $config->get('smtpPort'),
			'connection_config' => array()
		);
		if ($config->get('smtpAuth')) {
			$opts['connection_class'] = 'login';
			$opts['connection_config']['username'] = $config->get('smtpUsername');
			$opts['connection_config']['password'] = $config->get('smtpPassword');
		}
		if ($config->get('smtpSecurity')) {
			$opts['connection_config']['ssl'] = strtolower($config->get('smtpSecurity'));
		}

		$options = new SmtpOptions($opts);
		$this->transport->setOptions($options);
		
		$this->isGlobal = true;

		return $this;
	}

	public function send(Email $email)
	{
		$message = new Message();
		
		$config = $this->config;

		if ($email->get('from')) {
			$fromName = null;
			if (!empty($this->params['fromName'])) {
				$fromName = $this->params['fromName'];
			} else {
				$fromName = $config->get('outboundEmailFromName');
			}
			$message->addFrom(trim($email->get('from')), $fromName);
		} else {
			if (!empty($this->params['fromAddress'])) {
				$fromAddress = $this->params['fromAddress'];
			} else {
				if (!$config->get('outboundEmailFromAddress')) {
					throw new Error('outboundEmailFromAddress is not specified in config.');
				}
				$fromAddress = $config->get('outboundEmailFromAddress');
			}
			
			if (!empty($this->params['fromName'])) {
				$fromName = $this->params['fromName'];
			} else {
				$fromName = $config->get('outboundEmailFromName');
			}			

			$message->addFrom($fromAddress, $fromName);			
		}
		
		$value = $email->get('to');
		if ($value) {
			$arr = explode(';', $value);
			if (is_array($arr)) {
				foreach ($arr as $address) {
					$message->addTo(trim($address));				
				}
			}
		}
		
		$value = $email->get('cc');
		if ($value) {
			$arr = explode(';', $value);
			if (is_array($arr)) {
				foreach ($arr as $address) {
					$message->addCC(trim($address));
				}
			}
		}
		
		$value = $email->get('bcc');
		if ($value) {
			$arr = explode(';', $value);
			if (is_array($arr)) {
				foreach ($arr as $address) {
					$message->addBCC(trim($address));
				}
			}
		}

		$message->setSubject($email->get('name'));
		
		$body = new MimeMessage;		
		$parts = array();
				
		$bodyPart = new MimePart($email->get('body'));
		
		if ($email->get('isHtml')) {
			$bodyPart->type = 'text/html';	
		} else {
			$bodyPart->type = 'text/plain';
		}		
			
		$parts[] = $bodyPart;
		
		$aCollection = $email->get('attachments');
		if (!empty($aCollection)) {
			foreach ($aCollection as $a) {
				$fileName = 'data/upload/' . $a->id;
				$attachment = new MimePart(file_get_contents($fileName));
				$attachment->disposition = Mime::DISPOSITION_ATTACHMENT;
				$attachment->encoding = Mime::ENCODING_BASE64;
				$attachment->filename = $a->get('name');
				if ($a->get('type')) {
					$attachment->type = $a->get('type');
				}
				$parts[] = $attachment;
			}
		}		
		
		$body->setParts($parts);		
		$message->setBody($body);		

		try {
			$this->transport->send($message);
			$email->set('status', 'Sent');
			$email->set('dateSent', date("Y-m-d H:i:s"));
		} catch (\Exception $e) {
			throw new Error($e->getMessage(), 500);
		}
		
		$this->useGlobal();		
	}
}

