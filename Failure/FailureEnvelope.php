<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Failure;

use Gplanchat\Durable\Port\DeclaredActivityFailureInterface;

final readonly class FailureEnvelope
{
    /**
     * @param array<string, mixed>                                   $context
     * @param list<array{class: string, message: string, code: int}> $previousChain
     */
    public function __construct(
        public string $class,
        public string $message,
        public int $code = 0,
        public array $context = [],
        public ?string $trace = null,
        public array $previousChain = [],
    ) {
    }

    public static function fromThrowable(\Throwable $e, ?int $traceMaxLength = 4096): self
    {
        $trace = $e->getTraceAsString();
        if (null !== $traceMaxLength && \strlen($trace) > $traceMaxLength) {
            $trace = substr($trace, 0, $traceMaxLength).'... (truncated)';
        }

        $context = [];
        if ($e instanceof DeclaredActivityFailureInterface) {
            $context = [
                '_durable_declared' => true,
                '_durable_declared_class' => $e::class,
                '_durable_declared_payload' => $e->toActivityFailureContext(),
            ];
        } elseif (method_exists($e, 'toHistoryPayload')) {
            /** @var array<string, mixed> $context */
            $context = $e->toHistoryPayload();
        }

        return new self(
            $e::class,
            $e->getMessage(),
            (int) $e->getCode(),
            $context,
            $trace,
            self::extractPreviousChain($e),
        );
    }

    /**
     * @return list<array{class: string, message: string, code: int}>
     */
    public static function extractPreviousChain(\Throwable $e, int $maxDepth = 16): array
    {
        $chain = [];
        $previous = $e->getPrevious();
        $depth = 0;
        while (null !== $previous && $depth < $maxDepth) {
            $chain[] = [
                'class' => $previous::class,
                'message' => $previous->getMessage(),
                'code' => (int) $previous->getCode(),
            ];
            $previous = $previous->getPrevious();
            ++$depth;
        }

        return $chain;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'message' => $this->message,
            'code' => $this->code,
            'context' => $this->context,
            'trace' => $this->trace,
            'previousChain' => $this->previousChain,
        ];
    }
}
