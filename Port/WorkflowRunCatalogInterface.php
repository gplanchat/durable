<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\Observation\WorkflowRunDescription;

/**
 * Lecture seule : quelles exécutions existent, et ce qu'elles sont devenues.
 *
 * Le composant n'avait aucune surface de listage — {@see \Gplanchat\Durable\Store\EventStoreInterface}
 * ne lit qu'un flux par id d'exécution, {@see \Gplanchat\Durable\Store\WorkflowMetadataStore} qu'une
 * exécution à la fois. Un tableau de bord lit en travers des exécutions ; c'est un autre besoin, et
 * ce port est là pour qu'il ne soit pas servi en parlant gRPC ou SQL depuis la vue.
 *
 * Les implémentations rendent des {@see WorkflowRunDescription} : ce que le backend sait dire, et
 * rien qu'il ne saurait pas.
 */
interface WorkflowRunCatalogInterface
{
    /**
     * Les exécutions connues, de la plus récemment démarrée à la plus ancienne.
     *
     * @return list<WorkflowRunDescription>
     */
    public function listRuns(int $limit = 20): array;
}
