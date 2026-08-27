<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskCompleted;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\VersionMarked;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Failure\ActivityRetryState;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\Store\EventStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * Suite de conformité d'{@see EventStoreInterface} — DUR041.
 *
 * Un adaptateur prouve qu'il implémente le port en étendant cette classe et en rendant un store
 * neuf depuis {@see createEventStore()}. La référence, c'est
 * {@see \Gplanchat\Durable\Store\InMemoryEventStore}, et elle rejoue la suite elle aussi : une
 * référence qu'on ne vérifie pas est une définition, pas une garde.
 *
 * Ce que la suite garde, c'est la panne qui ne se voit pas à l'écriture. Un aller-retour qui
 * déforme un payload ne casse rien au moment de `append()` ; il casse le replay, plus tard, sur une
 * exécution qui reprend et lit autre chose que ce qu'elle avait écrit.
 *
 * ponytail: pas de fabrique d'événements paramétrable — la liste de {@see mappedEventFixtures()}
 * est en dur, et {@see testEveryEventTypeIsCoveredOrExplicitlyExcluded} la tient à jour à la place
 * d'une convention que personne ne relit.
 *
 * @see DUR041
 */
abstract class EventStoreConformanceTestCase extends TestCase
{
    /**
     * Un store vide, prêt à recevoir. Appelée une fois par cas.
     */
    abstract protected function createEventStore(): EventStoreInterface;

    // -----------------------------------------------------------------------------------------
    // Les cas
    // -----------------------------------------------------------------------------------------

    public function testAStreamComesBackInInsertionOrder(): void
    {
        $store = $this->createEventStore();
        $expected = [];

        foreach (self::mappedEventFixtures('exec-order') as $event) {
            $store->append($event);
            $expected[] = $event::class;
        }

        self::assertSame($expected, self::classesOf($store->readStream('exec-order')));
    }

    /**
     * Le cas central. La comparaison passe par l'enregistrement plutôt que par l'objet : c'est
     * l'enregistrement qui traverse le stockage, et deux instances égales ne prouvent rien si la
     * forme sur disque a bougé sous elles.
     */
    public function testEveryMappedEventTypeSurvivesTheRoundTrip(): void
    {
        $store = $this->createEventStore();
        $fixtures = self::mappedEventFixtures('exec-fidelity');

        foreach ($fixtures as $event) {
            $store->append($event);
        }

        $readBack = iterator_to_array($store->readStream('exec-fidelity'), false);

        self::assertCount(\count($fixtures), $readBack, 'le flux doit rendre autant d\'événements qu\'il en a reçu');

        foreach (array_values($fixtures) as $index => $original) {
            self::assertSame(
                EventDataMapper::fromDomainEvent($original),
                EventDataMapper::fromDomainEvent($readBack[$index]),
                \sprintf('%s ne survit pas à l\'aller-retour', $original::class),
            );
        }
    }

    /**
     * Le store DBAL rend un générateur adossé à une requête, le store in-memory un tableau. Les
     * deux moitiés comptent : une passe suffit, **et** un second appel repart du début. C'est la
     * seconde qu'un adaptateur rate — un générateur mémorisé une fois ne rejoue pas.
     */
    public function testAStreamIsConsumableOnceAndRestartsOnTheNextCall(): void
    {
        $store = $this->createEventStore();
        foreach (self::mappedEventFixtures('exec-passes') as $event) {
            $store->append($event);
        }

        $first = self::classesOf($store->readStream('exec-passes'));
        $second = self::classesOf($store->readStream('exec-passes'));

        self::assertNotSame([], $first);
        self::assertSame($first, $second, 'relire le flux doit rendre la même chose, pas rien');
    }

    public function testAPartiallyConsumedStreamDoesNotDisturbTheNextRead(): void
    {
        $store = $this->createEventStore();
        foreach (self::mappedEventFixtures('exec-partial') as $event) {
            $store->append($event);
        }

        foreach ($store->readStream('exec-partial') as $ignored) {
            break; // on abandonne le flux au premier élément
        }

        self::assertSame(
            \count(self::mappedEventFixtures('exec-partial')),
            \count(self::classesOf($store->readStream('exec-partial'))),
        );
    }

    public function testAStreamCarriesOneExecutionOnly(): void
    {
        $store = $this->createEventStore();

        $store->append(new ExecutionStarted('exec-a', ['who' => 'a']));
        $store->append(new ExecutionStarted('exec-b', ['who' => 'b']));
        $store->append(new ExecutionCompleted('exec-a', 'done-a'));

        $streamA = iterator_to_array($store->readStream('exec-a'), false);
        $streamB = iterator_to_array($store->readStream('exec-b'), false);

        self::assertCount(2, $streamA);
        self::assertCount(1, $streamB);
        foreach ($streamA as $event) {
            self::assertSame('exec-a', $event->executionId());
        }
        self::assertSame('exec-b', $streamB[0]->executionId());
    }

    public function testCountingAgreesWithTheStreamLength(): void
    {
        $store = $this->createEventStore();
        $fixtures = self::mappedEventFixtures('exec-count');

        self::assertSame(0, $store->countEventsInStream('exec-count'), 'un flux vide compte zéro');

        foreach (array_values($fixtures) as $index => $event) {
            $store->append($event);
            self::assertSame(
                $index + 1,
                $store->countEventsInStream('exec-count'),
                'le compte doit suivre chaque écriture',
            );
        }

        self::assertSame(
            \count(self::classesOf($store->readStream('exec-count'))),
            $store->countEventsInStream('exec-count'),
        );
    }

    /**
     * `recordedAt` est nullable par contrat — le store in-memory ne date rien. La suite garde donc
     * la **forme** et l'ordre, pas une valeur.
     */
    public function testRecordedAtYieldsTheSameEventsInTheSameOrder(): void
    {
        $store = $this->createEventStore();
        foreach (self::mappedEventFixtures('exec-dated') as $event) {
            $store->append($event);
        }

        $plain = self::classesOf($store->readStream('exec-dated'));
        $dated = [];

        foreach ($store->readStreamWithRecordedAt('exec-dated') as $entry) {
            self::assertArrayHasKey('event', $entry);
            self::assertArrayHasKey('recordedAt', $entry);
            self::assertInstanceOf(Event::class, $entry['event']);
            if (null !== $entry['recordedAt']) {
                self::assertInstanceOf(\DateTimeImmutable::class, $entry['recordedAt']);
            }
            $dated[] = $entry['event']::class;
        }

        self::assertSame($plain, $dated);
    }

    public function testAnUnknownExecutionIsEmptyRatherThanAnError(): void
    {
        $store = $this->createEventStore();
        $store->append(new ExecutionStarted('exec-known', []));

        self::assertSame([], self::classesOf($store->readStream('exec-nobody')));
        self::assertSame([], iterator_to_array($store->readStreamWithRecordedAt('exec-nobody'), false));
        self::assertSame(0, $store->countEventsInStream('exec-nobody'));
    }

    /**
     * La garde que DUR041 laisse derrière lui : une suite ne prouve que ce qu'on lui apprend à
     * demander. Ajouter un type d'événement sans le ranger d'un côté ou de l'autre échoue ici,
     * plutôt que d'élargir le journal sans élargir la garde.
     */
    public function testEveryEventTypeIsCoveredOrExplicitlyExcluded(): void
    {
        $declared = [];
        foreach (glob(__DIR__ . '/../Event/*.php') ?: [] as $file) {
            $short = basename($file, '.php');
            if ('Event' === $short) {
                continue; // l'interface elle-même
            }
            $declared[] = 'Gplanchat\\Durable\\Event\\' . $short;
        }
        sort($declared);

        $accounted = array_merge(
            array_keys(self::mappedEventFixtures('exec-coverage')),
            self::eventTypesOutsideTheJournal(),
        );
        sort($accounted);

        self::assertSame(
            $declared,
            $accounted,
            'un type d\'événement doit avoir une fixture, ou figurer dans eventTypesOutsideTheJournal()',
        );
    }

    // -----------------------------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------------------------

    /**
     * Un exemplaire de chaque type que {@see EventDataMapper} sait relire, avec des payloads
     * volontairement non scalaires : c'est là qu'un aller-retour JSON déforme.
     *
     * @return array<class-string<Event>, Event> indexé par classe, pour que la garde de couverture
     *                                           lise les clés
     */
    final protected static function mappedEventFixtures(string $executionId): array
    {
        // Les valeurs qui traversent mal un aller-retour JSON, réunies là où elles servent : une
        // référence SKU à zéro initial et un entier au-delà de PHP_INT_MAX ne survivent pas à un
        // `JSON_NUMERIC_CHECK` ni à un cast naïf, et c'est la panne que le replay ne verra qu'après
        // coup. Une mutation qui déforme le payload doit échouer ici.
        $nested = [
            'deep' => ['ratio' => 0.125, 'flags' => [true, false], 'label' => 'é✓ "quoted" \\ backslash'],
            'n' => 3,
            'sku' => '0042',
            'beyondIntMax' => '9223372036854775808',
            'exponent' => '1e3',
            'zero' => 0,
            'no' => false,
            'nothing' => null,
            'emptyList' => [],
        ];

        $events = [
            new ExecutionStarted($executionId, ['input' => $nested]),
            new ActivityScheduled($executionId, 'act-1', 'quote', ['lines' => ['a', 'b']], ['queue' => 'default']),
            new ActivityTaskStarted($executionId, 'act-1', 'quote', 1),
            new ActivityTaskFailed($executionId, 'act-1', 'quote', 1, \RuntimeException::class, 'transient', ActivityRetryState::InProgress),
            new ActivityTaskCompleted($executionId, 'act-1', $nested),
            new ActivityCompleted($executionId, 'act-1', $nested),
            new VersionMarked($executionId, 'ajout-remise', 1),
            new ActivityFailed(
                $executionId,
                'act-2',
                \LogicException::class,
                'nope',
                42,
                ['ctx' => $nested],
                '#0 {main}',
                [['class' => \RuntimeException::class, 'message' => 'cause', 'code' => 5]],
                'quote',
                3,
                ActivityRetryState::MaximumAttemptsReached,
            ),
            new ActivityCancelled($executionId, 'act-3', 'workflow cancelled'),
            ActivityCatastrophicFailure::fromStoredPayload($executionId, [
                'activityId' => 'act-4',
                'activityName' => 'quote',
                'attempt' => 2,
                'exceptionClass' => \Error::class,
                'exceptionMessage' => 'out of memory',
                'reasonCode' => 'fatal',
            ]),
            new TimerScheduled($executionId, 'timer-1', 1735689600.5, 'cooldown'),
            new TimerCompleted($executionId, 'timer-1'),
            new TimerCancelled($executionId, 'timer-2', 'superseded'),
            new SideEffectRecorded($executionId, 'side-1', $nested),
            new ChildWorkflowScheduled(
                $executionId,
                'child-1',
                'Child\\Type',
                ['payload' => $nested],
                ParentClosePolicy::Abandon,
                'requested-id',
                ['queue' => 'children'],
            ),
            new ChildWorkflowCompleted($executionId, 'child-1', $nested),
            new ChildWorkflowFailed($executionId, 'child-2', 'child blew up', 7, 'workflow_handler_failure', \LogicException::class, ['ctx' => $nested]),
            new WorkflowSignalReceived($executionId, 'approve', ['by' => 'someone', 'payload' => $nested]),
            new WorkflowUpdateHandled(
                $executionId,
                'amend',
                ['delta' => $nested],
                $nested,
                new FailureEnvelope(\LogicException::class, 'rejected', 9, ['ctx' => $nested], '#0 {main}', []),
            ),
            new WorkflowCancellationRequested($executionId, 'user asked', 'parent-1'),
            new WorkflowExecutionCancelled($executionId, 'user asked', 'parent-1'),
            new WorkflowContinuedAsNew($executionId, 'Next\\Type', ['carry' => $nested], ['reason' => 'history size']),
            WorkflowExecutionFailed::fromStoredPayload($executionId, [
                'kind' => WorkflowExecutionFailed::KIND_WORKFLOW_HANDLER,
                'failureClass' => \RuntimeException::class,
                'failureMessage' => 'handler threw',
                'failureCode' => 13,
                'context' => ['ctx' => $nested],
            ]),
            new ExecutionCompleted($executionId, $nested),
        ];

        $byClass = [];
        foreach ($events as $event) {
            $byClass[$event::class] = $event;
        }

        return $byClass;
    }

    /**
     * Les types que le journal ne porte pas. Nexus est servi côté appelant seulement (DUR036) : ces
     * événements naissent de l'historique Temporal, `EventDataMapper` ne les relit pas, et un
     * journal SQL n'en verra jamais. Les exclure ici est une décision écrite, pas un oubli.
     *
     * @return list<class-string<Event>>
     */
    protected static function eventTypesOutsideTheJournal(): array
    {
        return [
            \Gplanchat\Durable\Event\NexusOperationCancelled::class,
            \Gplanchat\Durable\Event\NexusOperationCompleted::class,
            \Gplanchat\Durable\Event\NexusOperationFailed::class,
            \Gplanchat\Durable\Event\NexusOperationScheduled::class,
            \Gplanchat\Durable\Event\NexusOperationTimedOut::class,
        ];
    }

    /**
     * @param iterable<Event> $stream
     *
     * @return list<class-string<Event>>
     */
    private static function classesOf(iterable $stream): array
    {
        $classes = [];
        foreach ($stream as $event) {
            $classes[] = $event::class;
        }

        return $classes;
    }
}
