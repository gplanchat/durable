<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;

/**
 * Lecture seule : quelles exécutions existent, et ce qu'elles sont devenues.
 *
 * Le composant n'avait aucune surface de listage — {@see \Gplanchat\Durable\Store\EventStoreInterface}
 * ne lit qu'un flux par id d'exécution, {@see \Gplanchat\Durable\Store\WorkflowMetadataStore} qu'une
 * exécution à la fois. Un tableau de bord lit en travers des exécutions ; c'est un autre besoin, et
 * ce port est là pour qu'il ne soit pas servi en parlant gRPC ou SQL depuis la vue.
 *
 * Les implémentations rendent des {@see \Gplanchat\Durable\Observation\WorkflowRunDescription} : ce
 * que le backend sait dire, et rien qu'il ne saurait pas.
 */
interface WorkflowRunCatalogInterface
{
    /**
     * Une page d'exécutions, de la plus récemment démarrée à la plus ancienne.
     *
     * @param WorkflowRunStatus|null $status `null` pour toutes les issues
     * @param string|null            $cursor `nextCursor` d'une page précédente, obtenu du même
     *                                       catalogue et avec le même filtre ; `null` pour la
     *                                       première page
     */
    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20): WorkflowRunPage;

    /**
     * L'historique enregistré d'une exécution, dans l'ordre où il a été enregistré.
     *
     * Prend la **description** et non l'identifiant seul : Temporal exige le workflow id en plus du
     * run id pour retrouver une histoire, et il vit dans `groupId`. Un port qui ne passerait que
     * l'identifiant obligerait l'appelant à le retrouver par ses propres moyens, c'est-à-dire à
     * savoir de quel backend il parle.
     *
     * Une exécution inconnue rend une liste vide : une exécution purgée, ou jamais vue, n'est pas
     * une erreur d'appel — la vue doit pouvoir l'afficher sans rien avoir à rattraper.
     *
     * @return list<WorkflowRunEvent>
     */
    public function readHistory(WorkflowRunDescription $run): array;
}
