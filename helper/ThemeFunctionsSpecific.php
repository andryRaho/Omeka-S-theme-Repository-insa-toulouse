<?php declare(strict_types=1);

namespace OmekaTheme\Helper;

use Contribute\Api\Representation\ContributionRepresentation;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Entity\User;

trait ThemeFunctionsSpecific
{
    protected $classesAccess = [
        'Accès libre' => 'free-access',
        'free' => 'free-access',
        'free-access' => 'free-access',
        'open' => 'free-access',
        'public' => 'free-access', // Recommandé

        'Accès restreint' => 'limited-access',
        'limited' => 'limited-access',
        'limited-access' => 'limited-access',
        'reserved' => 'limited-access', // Recommandé
        'restricted' => 'limited-access',

        'Non consultable' => 'no-access',
        'no-access' => 'no-access',
        'none' => 'no-access',
        'private' => 'no-access', // Défaut.
    ];

    protected $accessToLabels = [
        'Accès libre' => 'Accès libre',
        'free' => 'Accès libre',
        'free-access' => 'Accès libre',
        'open' => 'Accès libre',
        'public' => 'Accès libre', // Recommandé

        'Accès restreint' => 'Accès restreint',
        'limited' => 'Accès restreint',
        'limited-access' => 'Accès restreint',
        'reserved' => 'Accès restreint', // Recommandé
        'restricted' => 'Accès restreint',

        'Non consultable' => 'Non consultable',
        'no-access' => 'Non consultable',
        'none' => 'Non consultable',
        'private' => 'Non consultable', // Défaut.
    ];

    /**
     * Le type du document (le nom du modèle utilisé).
     *
     * Le type n'est pas forcément la classe, mais tout type, mais le formulaire ne le prévoit pas.
     */
    public function danteDocumentType(ItemRepresentation $resource, ?string $default = 'Travail étudiant'): string
    {
        // $label = $resource->displayResourceClassLabel($default);
        $template = $resource->resourceTemplate();
        $label = $template ? $template->label() : $default;
        // TODO Sans doute inutile désormais.
        return $label === 'Document' ? 'Mémoire' : $label;
    }

    public function danteAccess(AbstractResourceEntityRepresentation $resource): string
    {
        // Cette donnée est désormais remplie automatiquement.

        // Attention : curation:access est parfois privée et les visiteurs n'y
        // ont pas accès, ce qui peut rendre non consultable une ressource
        // publique.

        $value = $resource->value('curation:access');
        if ($value) {
            $v = $value->value();
        } else {
            // curation:access = 243
            $sql = <<<'SQL'
SELECT `value`.`value`
FROM `resource`
JOIN `value` ON `value`.`resource_id` = `resource`.`id` AND `value`.`property_id` = 243
WHERE `resource`.`id` = :resource_id
LIMIT 1
;
SQL;
            /** @var \Doctrine\DBAL\Connection $connection */
            $connection = $resource->getServiceLocator()->get('Omeka\Connection');
            $v = $connection
                ->executeQuery($sql, ['resource_id' => $resource->id()])->fetchOne()
                ?: 'Non consultable';
        }

        return $this->accessToLabels[$v] ?? 'Non consultable';
    }

    /**
     * @var \Omeka\Api\Representation\ValueRepresentation|string $value
     */
    public function danteAccessClass($value): string
    {
        return $this->classesAccess[(string) $value] ?? 'no-access';
    }

    public function danteAccessMedia(MediaRepresentation $media): bool
    {
        $access = $this->danteAccess($media);
        $accessCode = $this->danteAccessClass($access);
        if ($accessCode === 'free-access') {
            return true;
        }

        if ($accessCode === 'no-access') {
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
     * Tous les médias doivent être listés, y compris les médias privés (quand
     * l'item est accessible), afin de pouvoir afficher l'information "non consultable".
     *
     * Pour les documents entièrement privés, il faut juste le titre.
     *
     * Pour les thèses, il faut aussi le numéro de version.
     *
     * Néanmoins, ces fichiers sont mis en privé/réservé justement pour éviter
     * cela et cela devrait pouvoir être évité.
     */
    public function danteMediaItem(ItemRepresentation $item): array
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


    public function danteAuteur(ItemRepresentation $resource): string
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

    public function danteAnnee(ItemRepresentation $resource): string
    {
        $value = $resource->value('dcterms:date');
        return $value
            ? substr($value->value(), 0, 4)
            : 'sans date';
    }

    /**
     * Get the html citation from an item.
     */
    public function danteCitation(ItemRepresentation $resource, bool $short = false): string
    {
        static $escape;

        if (is_null($escape)) {
            $escape = $this->view->plugin('escapeHtml');
        }

        $auteur = $this->danteAuteur($resource);
        $annee = $this->danteAnnee($resource);
        $titre = $resource->displayTitle('sans titre');

        if ($short) {
            return sprintf(
                '<span class="dcterms-creator">%s</span> (<span class="dcterms:created">%s</span>), <span class="document-title dcterms-title">%s</span>',
                $auteur, $escape($annee), $escape($titre)
            );
        }

        $documentType = $this->danteDocumentType($resource);
        return sprintf(
            '<span class="dcterms-creator">%s</span> (<span class="dcterms:created">%s</span>), <span class="document-title dcterms-title">%s</span> [<span class="dcterms-type">%s</span>]',
            $auteur, $escape($annee), $escape($titre), $escape($documentType)
        );
    }

    public function danteUserAuteur(User $user): ?ItemRepresentation
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

    public function danteUserName(User $user): string
    {
        $author = $this->danteUserAuteur($user);
        return $author ? $author->value('foaf:familyName', ['default' => $user->getName()]) : $user->getName();
    }

    public function currentProcess(
        ?string $action = null,
        ?ContributionRepresentation $contribution = null,
        ?array $fields = null,
        ?string $mode = null
    ): array {
        static $steps;

        if (isset($steps)) {
            return $steps;
        }

        $params = $this->view->params();

        if ($action === 'add') {
            $step = $fields ? 'notice' : 'template';
            $mode = $contribution || ($step === 'template' && !empty($mode) && $mode === 'read') ? 'read' : 'write';
        } elseif ($action === 'edit') {
            $action = 'edit';
            $step = 'notice';
            $next = $params->fromQuery('next') ?? $params->fromPost('next') ?? '';
            [$nextAction, $nextQuery] = strpos($next, '-') === false ? [$next, null] : explode('-', $next, 2);
            if ($nextQuery) {
                if (strpos($nextQuery, '=') === false) {
                    $step = $nextQuery;
                } else {
                    [$nextQueryKey, $step] = explode('=', $nextQuery, 2);
                }
            } else if ($nextAction === 'notice' || $nextAction === 'fichiers') {
                $step = $nextAction;
            }
            $mode = $step === 'template' || (!empty($mode) && $mode === 'read') ? 'read' : 'write';
        } else {
            $action = 'show';
            $step = 'depot';
            $mode = 'read';
        }

        $current = "$action-$step";

        $stepNumbers = [
            'template' => 1,
            'notice' => 2,
            'fichiers' => 3,
            'depot' => 4,
        ];
        $stepNumber = $stepNumbers[$step] ?? 1;

        return $steps = [
            'currentActionStep' => $current,
            'action' => $action,
            'step' => $step,
            'mode' => $mode,
            'stepNumber' => $stepNumber,
        ];
    }

    /**
     * Pour gérer les options spécifiques directement.
     */
    public function templatePropertyThemeOption(
        ?\Omeka\Api\Representation\ResourceTemplatePropertyRepresentation $templateProperty,
        ?string $metadata = null
    ) {
        if (!$templateProperty || !$templateProperty instanceof \AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyRepresentation) {
            return null;
        }
        $val = $templateProperty->mainDataValueMetadata('settings', $metadata, '');
        if ($metadata === 'multilang') {
            $ls = [];
            foreach (array_map('trim', explode('|', trim((string) $val))) as $keyValue) {
                list($key, $value) = strpos($keyValue, '=') === false
                    ? [$keyValue, null]
                    : array_map('trim', explode('=', $keyValue, 2));
                if ($key !== '') {
                    $ls[$key] = $value;
                }
            }
            return $ls;
        }
        return $val;
    }

    public function sommaire(?string $pageSlugs, $tags = ['h1', 'h2']): array
    {
        /** @var \Omeka\Api\Representation\SitePageRepresentation $page */
        $page = $this->currentPage();
        if (!$page && !$pageSlugs) {
           return [];
        }

        if (!is_array($tags)) {
            $tags = [$tags];
        }

        if (!$pageSlugs) {
           return $this->extractTags($page, $tags);
        }

        $siteId = $this->currentSite()->id();

        $headers = [];
        $api = $this->view->api();
        foreach (array_map('trim', explode("\n", $pageSlugs)) as $pageSlug) {
           /** @var \Omeka\Api\Representation\SitePageRepresentation $sitePage */
           $sitePage = $api->searchOne('site_pages', ['site_id' => $siteId, 'slug' => $pageSlug])->getContent();
           if ($sitePage) {
               $pageUrl = $sitePage->siteUrl();
               // Page en cours.
               $headers[] = [
                   'level' => 0,
                   'tag' => null,
                   'page_id' => $sitePage->id(),
                   'page_slug' => $pageSlug,
                   'page_url' => $pageUrl,
                   'id' => null,
                   '#id' => null,
                   'label' => $sitePage->title(),
                   'page_url#id' => $pageUrl,
               ];
               $headers = array_merge($headers, $this->extractTags($sitePage, $tags));
           }
        }
        return $headers;
    }

    public function sommaireIds(?string $html, $tags = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']): string
    {
        if (!$html || !$tags) {
           return (string) $html;
        }

        if (!is_array($tags)) {
            $tags = [$tags];
        }

        $dom = new \DOMDocument('1.1', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        try {
            $html = '<div>' . mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8') . '</div>';
            @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOENT);
            /** @var \DOMElement $element */
            foreach ($tags as $tag) {
                foreach ($dom->getElementsByTagName($tag) ?: [] as $element){
                    $content = strip_tags((string) $element->textContent);
                    $id = $this->slugify($content);
                    $element->setAttributeNode(new \DOMAttr('id', $id));
                }
            }
            $html = mb_substr((string) @$dom->saveHTML(), 5, -7);
        } catch (\Exception $e) {
        }

        return $html;
    }

    /**
     * Extract any tag content associated with an id.
     *
     * It allows to build a table of content dynamically.
     *
     * @return array Array data to create links.
     */
    protected function extractTags(\Omeka\Api\Representation\SitePageRepresentation $page, array $tags = []): array
    {
        if (!$tags) {
            return [];
        }

        $idLabels = [];
        /** @var \Omeka\View\Helper\BlockLayout $blockLayoutRender */
        $blockLayoutRender = $this->view->blockLayout();
        $pageId = $page->id();
        $pageSlug = $page->slug();
        $pageUrl = $page->siteUrl();
        foreach ($page->blocks() as $block) {
            $layout = $block->layout();
            if (($layout === 'html' || $layout === 'block')
                && strpos((string) $block->dataValue('template'), 'aside')
            ) {
                continue;
            }
            $html = $blockLayoutRender->render($block);
            // preg_match_all('~<' . $tag . ' [^>]*>(.*)</' . $tag . '>~', $html, $matches);
            $dom = new \DOMDocument('1.1', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            try {
                $html = '<div>' . mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8') . '</div>';
                @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOENT);
                /** @var \DOMElement $element */
                if (count($tags) === 1) {
                    $tag = reset($tags);
                    $level = (int) substr($tag, 1, 1);
                    foreach ($dom->getElementsByTagName($tag) ?: [] as $element){
                        $content = strip_tags((string) $element->textContent);
                        $id = $this->slugify($content);
                        $idLabels[] = [
                            'level' => $level,
                            'tag' => $tag,
                            'page_id' => $pageId,
                            'page_slug' => $pageSlug,
                            'page_url' => $pageUrl,
                            'id' => $id,
                            '#id' => '#' . $id,
                            'label' => $content,
                            'page_url#id' => $pageUrl . '#' . $id,
                        ];
                    }
                } else {
                    $tags = array_map('strtolower', $tags);
                    foreach ($dom->getElementsByTagName('*') ?: [] as $element){
                        $tag = strtolower($element->tagName);
                        if (!in_array($tag, $tags)) {
                            continue;
                        }
                        $level = (int) substr($tag, 1, 1);
                        $content = strip_tags((string) $element->textContent);
                        $id = $this->slugify($content);
                        $idLabels[] = [
                            'level' => $level,
                            'tag' => $tag,
                            'page_id' => $pageId,
                            'page_slug' => $pageSlug,
                            'page_url' => $pageUrl,
                            'id' => $id,
                            '#id' => '#' . $id,
                            'label' => $content,
                            'page_url#id' => $pageUrl . '#' . $id,
                        ];
                    }
                }
            } catch (\Exception $e) {
            }
        }

        return $idLabels;
    }

    public function danteAdvancedTemplateValues($templateOrResourceOrContribution, array $values): array
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
