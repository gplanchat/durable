<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Versioning\ChangePoint;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;

/**
 * Le palier « replay » de DUR041 : au lieu d'écrire des événements fabriqués, il en fait produire
 * par un vrai workflow — activité, minuteur, deux effets de bord dont un payload imbriqué — puis
 * compare ce que le replay relit de l'adaptateur à ce qu'il relit de la référence.
 *
 * Un adaptateur qui pilote un workflow en ligne étend cette classe et hérite des deux paliers. Un
 * adaptateur adossé à un serveur doit n'étendre que {@see EventStoreConformanceTestCase} et rejouer
 * ce palier dans la suite d'intégration. La coupure est dans la déclaration de classe, pour qu'un
 * pont qui ne joue qu'une moitié de la suite soit un fait visible et non un oubli.
 *
 * Ce docbloc affirmait que les deux stores Temporal étendaient le palier port. **C'est faux** : ni
 * {@see \Gplanchat\Bridge\Temporal\TemporalJournalEventStore} ni
 * {@see \Gplanchat\Bridge\Temporal\Store\TemporalReadThroughEventStore} n'étend quoi que ce
 * soit, et aucun des deux paliers ne s'exécute contre eux. Un pont qui ne joue **aucune** moitié
 * n'était pas prévu par la coupure, et c'est précisément l'oubli qu'elle devait rendre visible.
 * Le change `backend-data-parity` le comble ; DUR041 porte l'état réel.
 *
 * La référence, elle, n'étend pas cette classe : on ne différencie pas un store d'avec lui-même.
 *
 * @see DUR041
 */
abstract class EventStoreReplayConformanceTestCase extends EventStoreConformanceTestCase
{
    public function testAWorkflowRunsIdenticallyAgainstTheReference(): void
    {
        $reference = new InMemoryEventStore();
        $subject = $this->createEventStore();

        $referenceResult = self::runConformanceWorkflow($reference, 'exec-reference');
        $subjectResult = self::runConformanceWorkflow($subject, 'exec-subject');

        self::assertSame($referenceResult, $subjectResult, 'le workflow doit rendre le même résultat');
        self::assertSame(
            self::journalShape($reference, 'exec-reference'),
            self::journalShape($subject, 'exec-subject'),
            'les deux journaux doivent enregistrer les mêmes événements, dans le même ordre',
        );
    }

    /**
     * `EventStoreHistorySource` relit le flux à chaque interrogation de slot — c'est le chemin que
     * le replay emprunte réellement, et il est plus exigeant qu'une lecture unique.
     */
    public function testReplaySlotLookupsAgreeWithTheReference(): void
    {
        $reference = new InMemoryEventStore();
        $subject = $this->createEventStore();

        self::runConformanceWorkflow($reference, 'exec-reference');
        self::runConformanceWorkflow($subject, 'exec-subject');

        $fromReference = new EventStoreHistorySource($reference, 'exec-reference');
        $fromSubject = new EventStoreHistorySource($subject, 'exec-subject');

        self::assertSame(
            $fromReference->findActivitySlotResult(0)['result'],
            $fromSubject->findActivitySlotResult(0)['result'],
        );
        self::assertNull($fromSubject->findActivitySlotResult(1), 'une seule activité a été planifiée');

        // Les effets de bord portent un `mixed` : c'est là qu'un aller-retour JSON déforme.
        self::assertSame($fromReference->findSideEffectForSlot(0), $fromSubject->findSideEffectForSlot(0));
        self::assertSame($fromReference->findSideEffectForSlot(1), $fromSubject->findSideEffectForSlot(1));

        self::assertNotNull($fromSubject->findScheduledTimerId(0), 'le minuteur doit être relu depuis le store');
    }

    /**
     * Les accesseurs d'identité que la garde de divergence interroge (DUR042).
     *
     * Ils sont sur le même port que les recherches de slot ci-dessus, mais ils ne répondent pas à
     * la même question : « qu'est-ce qui s'est passé ici » d'un côté, « qu'est-ce que c'était » de
     * l'autre. Un adaptateur peut très bien rendre le bon résultat au bon slot et se tromper
     * d'identité — et une garde qui compare la mauvaise identité vaut moins que pas de garde,
     * puisqu'elle refuserait des replays fidèles.
     *
     * Ici plutôt que dans un test de parité dédié à un adaptateur : tout store qui étend cette
     * classe hérite du contrôle, aujourd'hui comme demain.
     */
    public function testIdentityLookupsAgreeWithTheReference(): void
    {
        $reference = new InMemoryEventStore();
        $subject = $this->createEventStore();

        self::runConformanceWorkflow($reference, 'exec-reference');
        self::runConformanceWorkflow($subject, 'exec-subject');

        $fromReference = new EventStoreHistorySource($reference, 'exec-reference');
        $fromSubject = new EventStoreHistorySource($subject, 'exec-subject');

        self::assertSame(
            $fromReference->activityNameForSlot(0),
            $fromSubject->activityNameForSlot(0),
            "l'identité du slot d'activité doit survivre à l'aller-retour dans le store",
        );
        self::assertNotNull($fromSubject->activityNameForSlot(0), 'et ne pas être perdue en route');

        // Un slot que le workflow n'a pas atteint : null des deux côtés. C'est ce qui distingue
        // « rien à comparer » de « divergence », et un adaptateur qui répondrait la chaîne vide
        // ferait refuser un workflow qui grandit.
        self::assertNull($fromSubject->activityNameForSlot(1));
        self::assertSame($fromReference->activityNameForSlot(1), $fromSubject->activityNameForSlot(1));

        // Le workflow de conformité ne démarre aucun enfant et ce backend refuse Nexus (DUR036) :
        // les deux accesseurs doivent le dire, et le dire pareil.
        self::assertNull($fromSubject->childWorkflowTypeForSlot(0));
        self::assertSame($fromReference->childWorkflowTypeForSlot(0), $fromSubject->childWorkflowTypeForSlot(0));
        self::assertNull($fromSubject->nexusOperationSignatureForSlot(0));
        self::assertSame($fromReference->nexusOperationSignatureForSlot(0), $fromSubject->nexusOperationSignatureForSlot(0));
    }

    /**
     * La version qu'une exécution a enregistrée doit survivre à l'aller-retour dans le store.
     *
     * C'est la propriété dont dépend tout le versioning : au replay, la réponse vient du journal.
     * Un adaptateur qui la perdrait ferait reprendre à une exécution en vol l'autre branche — sans
     * rien signaler, puisque la garde de divergence, elle, verrait un code cohérent avec sa
     * nouvelle version.
     */
    public function testVersionLookupsAgreeWithTheReference(): void
    {
        $reference = new InMemoryEventStore();
        $subject = $this->createEventStore();

        self::runConformanceWorkflow($reference, 'exec-reference');
        self::runConformanceWorkflow($subject, 'exec-subject');

        $fromReference = new EventStoreHistorySource($reference, 'exec-reference');
        $fromSubject = new EventStoreHistorySource($subject, 'exec-subject');

        self::assertSame(1, $fromSubject->versionForChangeId('conformance-change'), 'la version enregistrée revient telle quelle');
        self::assertSame(
            $fromReference->versionForChangeId('conformance-change'),
            $fromSubject->versionForChangeId('conformance-change'),
        );

        // Un point que ce workflow n'a jamais déclaré : null des deux côtés. C'est ce qui
        // distingue « pas encore atteint » de « version 0 », et un adaptateur qui répondrait 0
        // ferait prendre l'ancienne branche à une exécution qui n'y a jamais eu droit.
        self::assertNull($fromSubject->versionForChangeId('jamais-declare'));
        self::assertSame(
            $fromReference->versionForChangeId('jamais-declare'),
            $fromSubject->versionForChangeId('jamais-declare'),
        );
    }

    private static function runConformanceWorkflow(EventStoreInterface $eventStore, string $executionId): mixed
    {
        $activityExecutor = new RegistryActivityExecutor();
        $activityExecutor->register('durable.conformance.quote', static fn(array $payload): array => [
            'total' => 42.5,
            'currency' => 'EUR',
            'lines' => $payload['lines'] ?? [],
        ]);

        $runner = new InMemoryWorkflowRunner(
            $eventStore,
            new InMemoryActivityTransport(),
            $activityExecutor,
            0,
            new WorkflowRegistry(),
        );

        return $runner->run($executionId, static function (WorkflowEnvironment $wf): array {
            // Un point de changement dans le workflow de conformité : c'est ce qui oblige chaque
            // adaptateur à faire l'aller-retour du marqueur de version, et pas seulement la
            // référence. Un store qui perdrait `VersionMarked` ferait rebasculer une exécution en
            // vol sur l'autre branche — en silence.
            $wf->version('conformance-change', ChangePoint::DEFAULT_VERSION, 1);
            $nested = $wf->sideEffect(static fn(): array => ['nested' => ['deep' => true], 'ratio' => 0.1]);
            $quote = $wf->await($wf->activityStub(ConformanceActivities::class)->quote(['a', 'b']));
            $wf->sleep(Duration::seconds(0.001));
            $flag = $wf->sideEffect(static fn(): string => 'after-timer');

            return ['nested' => $nested, 'quote' => $quote, 'flag' => $flag];
        });
    }

    /**
     * @return list<array{string, array<string, mixed>}> type d'événement + payload, dans l'ordre
     */
    private static function journalShape(EventStoreInterface $store, string $executionId): array
    {
        $shape = [];
        foreach ($store->readStream($executionId) as $event) {
            $shape[] = [$event::class, self::scrub($event->payload())];
        }

        return $shape;
    }

    /**
     * Deux exécutions distinctes tirent des identifiants et des horloges différents ; les
     * neutraliser laisse exactement ce que la comparaison doit porter : la forme du journal.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function scrub(array $payload): array
    {
        $volatile = [
            'timerId', 'activityId', 'sideEffectId', 'childExecutionId', 'executionId',
            'scheduledAt', 'queued_at', 'first_queued_at',
        ];

        foreach ($payload as $key => $value) {
            if (\in_array($key, $volatile, true)) {
                $payload[$key] = '<volatile>';
            } elseif (\is_array($value)) {
                $payload[$key] = self::scrub($value);
            }
        }

        return $payload;
    }
}
