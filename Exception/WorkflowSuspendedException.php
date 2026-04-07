<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Levée lorsque le workflow doit s'arrêter et être re-dispatché
 * (mode distribué, activité en attente).
 *
 * {@see shouldDispatchResume()} : faux pour signaux / updates — seuls les handlers
 * {@see \Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler} (etc.) doivent
 * relancer ; sinon transport Messenger **sync** boucle à l’infini.
 *
 * @see DUR021 Symfony Messenger integration (distributed resume)
 */
final class WorkflowSuspendedException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly bool $shouldDispatchResume = true,
        private readonly bool $waitingOnTimer = false,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Si vrai, {@see \Gplanchat\Durable\Bundle\Handler\WorkflowRunHandler} envoie un {@see \Gplanchat\Durable\Transport\WorkflowRunMessage} de reprise
     * (activité / timer à faire progresser par un worker).
     */
    public function shouldDispatchResume(): bool
    {
        return $this->shouldDispatchResume;
    }

    /**
     * Si vrai, l’attente porte sur un minuteur durable : ne pas enchaîner des reprises immédiates ;
     * planifier {@see \Gplanchat\Durable\Transport\FireWorkflowTimersMessage} (éventuellement avec délai Messenger).
     */
    public function waitingOnTimer(): bool
    {
        return $this->waitingOnTimer;
    }
}
