<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * Un événement de l'historique d'une exécution, dans le vocabulaire du composant.
 *
 * `label` est ce qu'un humain lit — le nom de l'activité, du signal, de la mise à jour — et non un
 * identifiant technique. Quand le backend ne sait vraiment pas nommer, l'identifiant est un
 * repli assumé, pas un défaut de conception : mieux vaut un id qu'une ligne sans nom.
 *
 * `sequence` est l'ordre d'enregistrement, pas un identifiant : il sert à ranger, et deux backends
 * n'ont aucune raison de numéroter pareil.
 *
 * `details` est ce qu'une ligne ne tient pas : l'entrée d'une activité, son résultat, la classe et
 * le message d'un échec, les délais d'une planification. Une frise sans lui répond « quoi » et
 * jamais « avec quoi » — et c'est la deuxième question que se pose un exploitant, aussitôt après
 * la première. Vide est une réponse valable : tous les événements n'ont pas de quoi la remplir.
 *
 * ⚠ Le contenu est **le vocabulaire du backend**, pas le nôtre : les clés d'un journal maison ne
 * sont pas celles de l'historique Temporal. Le normaliser demanderait de décider, pour chaque
 * backend, ce qui mérite un nom commun — un travail qui n'a de sens qu'une fois qu'on aura vu ce
 * que les exploitants y cherchent. En attendant, montrer la forme brute ne ment pas.
 *
 * `actionKey` est **l'action** dont l'événement fait partie : une activité planifiée, démarrée puis
 * terminée est une action et trois événements. Sans lui, une frise ne peut que ranger par nature —
 * « les activités », « les signaux » — et l'exploitant qui veut savoir combien de temps *cette*
 * activité a duré doit recoller trois lignes de l'œil. La clé n'a pas de sens hors de son exécution
 * et n'en a pas besoin : elle sert à regrouper, pas à désigner.
 *
 * `null` veut dire « cet événement est à lui seul son action » — le démarrage d'une exécution, un
 * signal reçu. C'est une réponse, pas une absence de réponse.
 *
 * `started` dit que **le travail commence ici** : un worker a pris la tâche, l'exécution enfant a
 * démarré, l'opération a débuté. Ce qui précède un tel événement dans son action n'est donc pas du
 * travail mais une **attente de prise en charge** — la file. Deux barres de même longueur ne
 * racontent pas la même chose selon que le temps a été passé à travailler ou à attendre que
 * quelqu'un veuille bien commencer, et c'est la première question d'un exploitant devant une
 * exécution lente : est-ce mon code, ou est-ce que personne n'a répondu ?
 *
 * `failed` marque **l'événement** qui a mal tourné, pas l'action ni l'exécution : une activité
 * reprise après deux échecs porte du rouge et se termine bien, et c'est exactement ce qu'un
 * exploitant doit pouvoir lire d'un coup d'œil. Une annulation et une interruption n'en sont pas :
 * ce sont des issues, décidées par quelqu'un, et les peindre comme des pannes enverrait chercher
 * une panne là où il n'y a qu'une décision.
 *
 * @phpstan-type Details array<string, mixed>
 */
final readonly class WorkflowRunEvent
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public int $sequence,
        public \DateTimeImmutable $recordedAt,
        public WorkflowRunEventKind $kind,
        public string $label,
        public array $details = [],
        public ?string $actionKey = null,
        public bool $started = false,
        public bool $failed = false,
    ) {}
}
