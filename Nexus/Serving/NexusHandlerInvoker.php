<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

use Gplanchat\Durable\Activity\PayloadToContractMethodInvoker;

/**
 * Adapts an incoming Nexus task onto the method the handler wrote.
 *
 * Without it, the gap between the two ends is twofold, and both halves are `TypeError`s:
 * {@see NexusOperationRegistry::dispatch()} calls its handler with **the whole payload as argument
 * #1** and expects a {@see NexusOperationResponse}, whereas the handler wrote the signature of its
 * contract and returns the type that contract declares.
 *
 * The mapping is the activities' one, word for word — the payload is keyed by parameter name at
 * writing time ({@see \Gplanchat\Durable\Nexus\NexusStub::argumentsToPayload()}) and read back by
 * name here —, hence the reuse of {@see PayloadToContractMethodInvoker} rather than a second copy
 * of the same loop.
 *
 * What remains its own is the wrapping: an immediate handler returns a business value, and it is
 * the plumbing that turns it into a response. Writing it in the handler would force everyone to
 * know a plumbing type just to say "here it is".
 */
final readonly class NexusHandlerInvoker
{
    private PayloadToContractMethodInvoker $invoker;

    /**
     * @param class-string $contractClass
     */
    public function __construct(
        object $handler,
        private string $contractClass,
        private string $contractMethodName,
    ) {
        $this->invoker = new PayloadToContractMethodInvoker($handler, $contractClass, $contractMethodName);
    }

    public function __invoke(mixed $payload): NexusOperationResponse
    {
        if (!\is_array($payload)) {
            // Trust boundary: the payload comes from the network, and a caller that is not a
            // Durable stub can send anything at all. Refusing it by naming it is better than
            // letting reflection fail on a message that talks about parameters.
            throw new \InvalidArgumentException(\sprintf(
                'A Nexus payload for %s::%s() must be a JSON object keyed by parameter name, got %s.',
                $this->contractClass,
                $this->contractMethodName,
                get_debug_type($payload),
            ));
        }

        return NexusOperationResponse::completed(($this->invoker)($payload));
    }
}
