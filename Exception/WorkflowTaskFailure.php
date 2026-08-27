<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Cette **tentative** a échoué ; l'exécution, elle, est intacte.
 *
 * Toute autre exception qui s'échappe du code de workflow termine l'exécution en échec. C'est le
 * bon comportement pour un échec métier : le workflow a fini, mal, et son historique le dit.
 *
 * Il existe des échecs d'une autre nature — le moteur ne peut pas rejouer cet historique avec ce
 * code-là. Terminer l'exécution serait alors le pire choix disponible : la cause est un
 * déploiement, et un déploiement s'annule. Une exécution morte, non.
 *
 * Cette exception demande donc d'échouer la tâche et de laisser l'exécution reprenable : aucune
 * commande n'est émise, l'historique n'apprend rien de la tentative, et le serveur redonne la
 * tâche. Remettre le code qui a écrit l'historique suffit à repartir.
 *
 * Mesuré, et non supposé : sonde 1.2 de `workflow-replay-divergence-guard` contre un serveur
 * `start-dev` 1.31.2. Lever depuis le code de workflow produisait `WORKFLOW_TASK_COMPLETED` puis
 * `WORKFLOW_EXECUTION_FAILED`, et remettre l'ancien code ne ressuscitait rien.
 *
 * Seul le backend Temporal a la notion de *tâche*. Ailleurs, cette exception se comporte comme
 * n'importe quelle autre — voir la note dans le change.
 */
final class WorkflowTaskFailure extends \RuntimeException {}
