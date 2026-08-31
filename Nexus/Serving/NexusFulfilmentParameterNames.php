<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

/**
 * Les noms de paramètres d'un workflow qui remplit une opération doivent être ceux du contrat.
 *
 * **La panne que ce garde remplace est muette.** La charge d'une opération Nexus est clée par nom
 * aux deux bouts : l'appelant l'écrit depuis la signature du contrat, et le workflow la relit par
 * `mapInputToArguments()`. Un paramètre renommé d'un seul côté ne casse rien à l'écriture, ne lève
 * rien à l'exécution, et arrive simplement à `null`. Le seul moment où l'on peut encore le dire est
 * l'enregistrement, avant qu'une tâche n'arrive.
 *
 * **Pourquoi le garde est ici et non chez un hôte.** Il l'a été : `NexusHandlerPass` le portait en
 * privé, donc le refus n'existait que pour les applications Symfony. Un second hôte servant —
 * `gplanchat/durable-laravel`, qui déclare ses gestionnaires dans `config/durable.php` — l'aurait
 * réécrit à l'identique, ou, plus probablement, ne l'aurait pas écrit du tout. Deux réflexions et
 * une lecture de `#[AsWorkflowMethod]` ne demandent aucun conteneur : rien dans ce contrôle
 * n'appartenait à un framework.
 *
 * **Un paramètre facultatif passe**, et ce n'est pas une tolérance : donner une valeur par défaut à
 * un paramètre que le contrat ne porte pas est une décision — c'est dire « si personne ne me
 * l'envoie, voici ce que je fais ». C'est l'absence de défaut qui trahit l'attente déçue.
 */
final class NexusFulfilmentParameterNames
{
    /**
     * @param string       $refusedBy      ce que le lecteur doit aller corriger : la balise Symfony,
     *                                     la clé de configuration Laravel — le mécanisme qui refuse,
     *                                     pas la classe qui l'implémente
     * @param class-string $contract
     * @param class-string $workflowClass
     *
     * @throws \LogicException si un paramètre obligatoire du workflow ne correspond à rien
     */
    public static function assertMatch(
        string $refusedBy,
        string $contract,
        string $contractMethod,
        string $operation,
        string $workflowClass,
    ): void {
        $expected = [];
        foreach ((new \ReflectionMethod($contract, $contractMethod))->getParameters() as $parameter) {
            $expected[$parameter->getName()] = true;
        }

        $orphans = [];
        foreach ((new WorkflowDefinitionLoader())->workflowMethodParameters($workflowClass) as $name => $optional) {
            if (!$optional && !isset($expected[$name])) {
                $orphans[] = $name;
            }
        }

        if ([] === $orphans) {
            return;
        }

        throw new \LogicException(\sprintf(
            '%s: workflow %s fulfils operation "%s" of %s, but its parameter(s) $%s match nothing in %s::%s(%s). The payload is keyed by parameter name at both ends, so each of them would silently receive null.',
            $refusedBy,
            $workflowClass,
            $operation,
            $contract,
            implode(', $', $orphans),
            $contract,
            $contractMethod,
            [] === $expected ? '' : '$' . implode(', $', array_keys($expected)),
        ));
    }
}
