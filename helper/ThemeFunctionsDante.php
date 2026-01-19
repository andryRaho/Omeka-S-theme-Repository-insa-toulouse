<?php declare(strict_types=1);

namespace OmekaTheme\Helper;

use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Entity\User;

trait ThemeFunctionsDante
{
    /**
     * @deprecated Utiliser les nouvelles options du module Access.
     */
    public function accessMedia(MediaRepresentation $media): bool
    {
        $accessLevel = $this->accessLevel($media);
        if ($accessLevel === 'free') {
            return true;
        }

        if ($accessLevel === 'forbidden') {
            return false;
        }

        $user = $this->view->identity();
        if (!$user) {
            return false;
        }

        $template = $media->resourceTemplate();
        if (!$template) {
            return false;
        }

        $templateLabel = $template->label();
        if ($templateLabel === 'Fichier (thèse)') {
            return true;
        }

        // return (bool) strpos($user->getEmail(), 'univ-tlse2.fr');
        return $user->getRole() !== 'guest_ext';
    }

    /**
     * Le type du document (le nom du modèle utilisé).
     *
     * Le type n'est pas forcément la classe, mais tout type, mais le formulaire ne le prévoit pas.
     */
    public function documentType(ItemRepresentation $resource, ?string $default = 'Travail étudiant'): string
    {
        // $label = $resource->displayResourceClassLabel($default);
        $template = $resource->resourceTemplate();
        $label = $template ? $template->label() : $default;
        // TODO Sans doute inutile désormais.
        return $label === 'Document' ? 'Mémoire' : $label;
    }

    /**
     * Get the html citation from an item.
     */
    public function citation(ItemRepresentation $resource, bool $short = false): string
    {
        static $escape;

        if (is_null($escape)) {
            $escape = $this->view->plugin('escapeHtml');
        }

        $auteur = $this->formatAuthor($resource);
        $annee = $this->year($resource);
        $titre = $resource->displayTitle('sans titre');

        if ($short) {
            return sprintf(
                '<span class="dcterms-creator">%s</span> (<span class="dcterms:created">%s</span>), <span class="document-title dcterms-title">%s</span>',
                $auteur, $escape($annee), $escape($titre)
            );
        }

        $documentType = $this->documentType($resource);
        return sprintf(
            '<span class="dcterms-creator">%s</span> (<span class="dcterms:created">%s</span>), <span class="document-title dcterms-title">%s</span> [<span class="dcterms-type">%s</span>]',
            $auteur, $escape($annee), $escape($titre), $escape($documentType)
        );
    }

    /**
     * Tous les médias doivent être listés, y compris les médias privés (quand
     * l'item est accessible), afin de pouvoir afficher l'information "non consultable".
     *
     * Pour les documents entièrement privés, il faut juste le titre.
     *
     * Pour les thèses, il faut aussi le numéro de version.
     *
     * Néanmoins, ces fichiers sont mis en privé/réservé justement pour éviter
     * cela et cela devrait pouvoir être évité.
     *
     * @deprecated Utiliser les nouvelles options du module Access et ne pas mettre les documents en privés.
     */
    public function mediaItem(ItemRepresentation $item): array
    {
        // 275 = dante:version
        $sql = <<<'SQL'
            SELECT
                `resource`.`id` AS "id",
                `resource`.`id` AS "o:id",
                "" AS "resource",
                `resource`.`title` AS "o:title",
                `resource`.`is_public` AS "o:is_public",
                `value`.`value` AS "dante:version"
            FROM `resource`
            JOIN `media` ON `media`.`id` = `resource`.`id`
            LEFT JOIN `value` ON `value`.`resource_id` = `media`.`id` AND `value`.`property_id` = 275
            WHERE `media`.`item_id` = :item_id
            ;
            SQL;
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $item->getServiceLocator()->get('Omeka\Connection');
        $medias = $connection
            ->executeQuery($sql, ['item_id' => $item->id()])->fetchAllAssociativeIndexed();
        foreach ($item->media() as $media) {
            $medias[$media->id()]['resource'] = $media;
        }
        return $medias;
    }

    /**
     * Formate l'auteur, qui peut être une ressource liée.
     */
    public function formatAuthor(ItemRepresentation $resource): string
    {
        if ($value = $resource->value('dcterms:creator')) {
            if ($vr = $value->valueResource()) {
                $familyName = $vr->value('foaf:familyName');
                $givenName = $vr->value('foaf:givenName');
                $name = ($familyName ? '<span class="foaf-familyName">' . $familyName->asHtml() . '</span>' : '')
                    . ($givenName ? ($familyName ? ', ' : '') . '<span class="foaf-givenName">' . $givenName->asHtml() . '</span>' : '');
                if (!$name) {
                    $name = $vr->value('foaf:name')->asHtml();
                }
            } elseif ($uri = $value->uri()) {
                $name = $value->value() ?: $uri;
            } else {
                $name = $value->asHtml();
            }
        } else {
            $name = 'Anonyme';
        }

        return $name;
    }

    public function userAuthor(User $user): ?ItemRepresentation
    {
        static $author = false;

        if ($author !== false) {
            return $author;
        }

        $api = $this->view->api();

        // L'api ne permet pas la recherche sur le nom de la classe directement.
        $resourceClass = $api->searchOne('resource_classes', ['term' => 'foaf:Person'])->getContent();
        $author = $api->searchOne('items', [
            'resource_class_id' => $resourceClass->id(),
            // FIXME Utiliser l'email, pas le nom.
            'property' => [['property' => 'foaf:mbox', 'type' => 'eq', 'text' => $user->getEmail()]]
        ])->getContent();
        if (!$author) {
            $author = $api->searchOne('items', [
                'resource_class_id' => $resourceClass->id(),
                // FIXME Utiliser l'email, pas le nom.
                'property' => [['property' => 'foaf:name', 'type' => 'eq', 'text' => $user->getName()]]
            ])->getContent();
        }
        return $author;
    }

    public function userName(User $user): string
    {
        $author = $this->userAuthor($user);
        return $author ? $author->value('foaf:familyName', ['default' => $user->getName()]) : $user->getName();
    }

    public function advancedTemplateValues($templateOrResourceOrContribution, $values): array
    {
        if (!$templateOrResourceOrContribution) {
            return $values;
        }
        $template = $templateOrResourceOrContribution instanceof \Omeka\Api\Representation\ResourceTemplateRepresentation
            ? $templateOrResourceOrContribution
            : $templateOrResourceOrContribution->resourceTemplate();

        $vals = [];
        foreach ($values as $term => $propertyData) {
            $propertyId = $propertyData['property']->id();
            $templateProperty = $template->resourceTemplateProperty($propertyId);
            if (!$templateProperty) {
                $vals[$term] = $propertyData;
                continue;
            }
            $multilang = $this->templatePropertyThemeOption($templateProperty, 'multilang');
            if (!$multilang) {
                $vals[$term] = $propertyData;
                continue;
            }

            // Avec multilang, il faut un label spécifique pour chaque langue.

            // La langue correspond à l'ordre des valeurs (deux pour les mémoires, trois pour les thèses).
            // Devrait néanmoins être enregistré (pour les ressources).

            // Pour les contributions et les ressources.

            if (isset($propertyData['contributions'])) {
                $isThese = $template->label() === 'Thèse';
                $pdContributions = array_values($propertyData['contributions']);
                foreach ($pdContributions as $index => $pdContribution) {
                    if ($index === 0) {
                        $lang = 'fra';
                    } elseif ($isThese && $index === 1) {
                        $lang = 'eng';
                    } else {
                        $lang = 'und';
                    }
                    if ($lang && isset($multilang[$lang])) {
                        $idx = $term . '/' . $lang;
                        if (!isset($vals[$idx])) {
                            $vals[$idx] = $propertyData;
                            $vals[$idx]['alternate_label'] = $multilang[$lang];
                            $vals[$idx]['term'] = $term;
                            $vals[$idx]['contributions'] = [];
                        }
                        $vals[$idx]['contributions'][] = $pdContribution;
                        unset($pdContributions[$index]);
                    }
                }
                if (count($pdContributions)) {
                    $vals[$term] = $propertyData;
                    $vals[$term]['term'] = $term;
                    $vals[$term]['contributions'] = $pdContributions;
                }
            } else {
                /** @var \Omeka\Api\Representation\ValueRepresentation $pdValue */
                $pdValues = array_values($propertyData['values']);
                foreach ($pdValues as $index => $pdValue) {
                    $lang = $pdValue->lang();
                    if ($lang && isset($multilang[$lang])) {
                        $idx = $term . '/' . $lang;
                        if (!isset($vals[$idx])) {
                            $vals[$idx] = $propertyData;
                            $vals[$idx]['alternate_label'] = $multilang[$lang];
                            $vals[$idx]['term'] = $term;
                            $vals[$idx]['values'] = [];
                        }
                        $vals[$idx]['values'][] = $pdValue;
                        unset($pdValues[$index]);
                    }
                }
                if (count($pdValues)) {
                    $vals[$term] = $propertyData;
                    $vals[$term]['term'] = $term;
                    $vals[$term]['values'] = $pdValues;
                }
            }
        }

        return $vals;
    }
}
