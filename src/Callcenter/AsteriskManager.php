<?php

/**
 * This is based on the PAMI\Client\Impl\ClientImpl class
 * by Marcelo Gornstein
 *
 * @link       http://marcelog.github.com/PAMI/
 */

namespace Callcenter;

use PAMI\Message\Event\EventMessage;
use PAMI\Message\Action\LoginAction;
use PAMI\Message\Action\QueuePauseAction;
use PAMI\Message\Action\QueueUnpauseAction;
use PAMI\Message\Response\ResponseMessage;
use PAMI\Message\IncomingMessage;
use PAMI\Message\Message;
use PAMI\Message\Event\Factory\Impl\EventFactoryImpl;
use Psr\Log\NullLogger;
use Evenement\EventEmitter;

class AsteriskManager extends EventEmitter implements \PAMI\Client\IClient,
    \PAMI\Listener\IEventListener
{

    /**
     * PSR-3 logger.
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var \React\Stream\DuplexResourceStream
     */
    public $stream;

    /**
     * @var array
     */
    public $options;

    /**
     * Event factory.
     * @var EventFactoryImpl
     */
    private $eventFactory;

    /**
     * Our event listeners
     * @var IEventListener[]
     */
    private $eventListeners;

    /**
     * The receiving queue.
     * @var IncomingMessage[]
     */
    private $incomingQueue;

    /**
     * This should not happen. Asterisk may send responses without a
     * corresponding ActionId.
     * @var string
     */
    private $lastActionId;

    /**
     * Event mask to apply on login action.
     * @var string|null
     */
    private $eventMask;

    /**
     * AsteriskManager constructor.
     * @param \React\Stream\DuplexResourceStream $stream
     * @param array $options
     */
    public function __construct(
        \React\Stream\DuplexResourceStream $stream,
        array $options
    )
    {
        $this->logger = new NullLogger();
        $this->options = $options;
        $this->stream = $stream;

        $this->eventMask = isset($options['event_mask']) ? $options['event_mask'] : null;
        $this->eventListeners = array();
        $this->eventFactory = new EventFactoryImpl();
        $this->incomingQueue = array();
        $this->lastActionId = false;

        $that = $this;

        $stream->on('data', function ($data) use ($that) {
            $that->messageDispatcher($data);
        });

        $this->registerEventListener($this);

        $loginaction = (new LoginAction(
            $this->options['username'],
            $this->options['password']
        ))->serialize();

        $this->stream->write(
            $loginaction
        );
    }

    /**
     *
     */
    public function open()
    {
    }

    /**
     * @param EventMessage $event
     */
    public function handle(EventMessage $event)
    {
        switch ($event->getName()) {
            case "UserEvent":
                switch ($event->getUserEventName()) {
                    case 'CALLER':
                        $this->emit('caller.new', [
                            $event->getKey('calleridnum'),
                            $event->getKey('uniqueid')
                        ]);
                        $this->logger->debug("UID of caller ".$event->getKey('calleridnum')." is ".$event->getKey('uniqueid'));
                        break;
                    case 'CALLERHANGUP':
                        $this->emit('caller.hangup', [
                            $event->getKey('calleridnum'),
                            $event->getKey('uniqueid')
                        ]);
                        break;
                    case 'LOGGEDIN':
                        $this->emit('agent.loggedin', [
                            $event->getKey('calleridnum')
                        ]);

                        $this->logger->debug("UID of agent ".$event->getKey('calleridnum')." is ".$event->getKey('uniqueid'));
                        break;
                    case 'LOGGEDOUT':
                        $this->emit('agent.loggedout', [
                            $event->getKey('calleridnum')
                        ]);
                        break;
                }
                break;
            case "QueueMemberPause":
                $agentstatus = strtolower(($event->getKey("paused") == 1)?"PAUSED":"AVAIL");

                $this->emit("agent.{$agentstatus}", [
                    $event->getKey('calleridnum')
                ]);
                break;
            case "QueueMemberStatus":
                //echo "\n".$event->serialize()."\n";
                break;
            case "QueueCallerJoin":
                $this->emit('caller.queued', [
                    $event->getKey('calleridnum'),
                    $event->getKey('uniqueid'),
                    $event->getKey('queue'),
                ]);
                break;
            case "BridgeEnter":
                $agentid = $event->getKey('calleridnum');
                $callerid = $event->getKey('connectedlinenum');
                $uid = $event->getKey('linkedid');

                if ($agentid and $callerid) {
                    $this->emit('queue.connect', [
                        $agentid,
                        $callerid,
                        $uid,
                    ]);
                }
                break;
            default:
                break;
        }
    }

    public function unpauseAgent($agentid)
    {
        $channel = "local/{$agentid}@agent-connect";

        $this->stream->write(
            (new QueueUnpauseAction(
                $channel
            ))->serialize()
        );

        $this->logger->debug("Unpause $channel");
    }

    public function pauseAgent($agentid)
    {
        $channel = "local/{$agentid}@agent-connect";

        $this->stream->write(
            (new QueuePauseAction(
                "local/{$agentid}@agent-connect"
            ))->serialize()
        );

        $this->logger->debug("Pause $channel");
    }

    /**
     * @param $data
     */
    public function messageDispatcher($data)
    {
        $msgs = [];

        while (($marker = strpos($data, Message::EOM))) {
            $msg = substr($data, 0, $marker);

            $data = substr(
                $data,
                $marker + strlen(Message::EOM)
            );

            $msgs[] = $msg;
        }

        foreach ($msgs as $aMsg) {
            $resPos = strpos($aMsg, 'Response:');
            $evePos = strpos($aMsg, 'Event:');

            if (($resPos !== false) &&
                (($resPos < $evePos) || $evePos === false)
            ) {
                $response = $this->messageToResponse($aMsg);
                $this->incomingQueue[$response->getActionId()] = $response;
            } elseif ($evePos !== false) {
                $event = $this->messageToEvent($aMsg);
                $response = $this->findResponse($event);
                if ($response === false || $response->isComplete()) {
                    $this->dispatch($event);
                } else {
                    $response->addEvent($event);
                }
            } else {
                $bMsg = 'Event: ResponseEvent' . "\r\n";
                $bMsg .= 'ActionId: ' . $this->lastActionId . "\r\n" . $aMsg;
                $event = $this->messageToEvent($bMsg);
                if ($response = $this->findResponse($event)) {
                    $response->addEvent($event);
                }
            }
        }
    }

    /**
     * @param IncomingMessage $message
     */
    protected function dispatch(IncomingMessage $message)
    {
        foreach ($this->eventListeners as $data) {
            $listener = $data[0];
            $predicate = $data[1];
            if (is_callable($predicate) && !call_user_func($predicate, $message)) {
                continue;
            }
            if ($listener instanceof \Closure) {
                $listener($message);
            } elseif (is_array($listener)) {
                $listener[0]->{$listener[1]}($message);
            } else {
                $listener->handle($message);
            }
        }
    }

    /**
     * @throws \Exception
     */
    public function process()
    {
        throw new \Exception("Not implemented. See dispatch() and handle()");
    }

    /**
     * @param mixed $listener
     * @param null $predicate
     * @return string
     */
    public function registerEventListener($listener, $predicate = null)
    {
        $listenerId = uniqid('PamiListener');
        $this->eventListeners[$listenerId] = array($listener, $predicate);
        return $listenerId;
    }

    /**
     * @param string $listenerId
     */
    public function unregisterEventListener($listenerId)
    {
        if (isset($this->eventListeners[$listenerId])) {
            unset($this->eventListeners[$listenerId]);
        }
    }

    /**
     * @throws \Exception
     */
    public function close()
    {
        throw new \Exception("Not implemented.");
    }

    /**
     * @param \PAMI\Message\OutgoingMessage $message
     * @return ResponseMessage|void
     */
    public function send(\PAMI\Message\OutgoingMessage $message)
    {
        $this->stream->write($message->serialize());
    }

    /**
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function setLogger(\Psr\Log\LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Tries to find an associated response for the given message.
     *
     * @param IncomingMessage $message Message sent by asterisk.
     *
     * @return \PAMI\Message\Response\ResponseMessage
     */
    protected function findResponse(IncomingMessage $message)
    {
        $actionId = $message->getActionId();

        if (isset($this->incomingQueue[$actionId])) {
            return $this->incomingQueue[$actionId];
        }

        return false;
    }

    /**
     * Returns a ResponseMessage from a raw string that came from asterisk.
     *
     * @param string $msg Raw string.
     *
     * @return \PAMI\Message\Response\ResponseMessage
     */
    private function messageToResponse($msg) : ResponseMessage
    {
        $response = new ResponseMessage($msg);
        $actionId = $response->getActionId();
        if (is_null($actionId)) {
            $actionId = $this->lastActionId;
            $response->setActionId($this->lastActionId);
        }
        return $response;
    }

    /**
     * Returns a EventMessage from a raw string that came from asterisk.
     *
     * @param string $msg Raw string.
     *
     * @return \PAMI\Message\Event\EventMessage
     */
    private function messageToEvent($msg)
    {
        return $this->eventFactory->createFromRaw($msg);
    }
}