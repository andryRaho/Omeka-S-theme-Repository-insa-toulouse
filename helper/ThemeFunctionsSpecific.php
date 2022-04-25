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

    protected $accessLevels = [
        'free-access' => 0,
        'limited-access' => 1,
        'no-access' => 2,
    ];

    protected $accessLabels = [
        'public' => 'Accès libre',
        'reserved' => 'Accès restreint',
        'private' =>'Non consultable',
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
        return $label === 'Document' ? 'Mémoire' : $label;
    }

    /**
     * Toutes les métadonnées sont accessibles : le type d'accès est donc défini
     * par le média le plus libre.
     *
     * On ne regarde pas l'embargo ici : il est déjà pris en compte (mise en public).
     */
    public function danteAccess(AbstractResourceEntityRepresentation $resource): string
    {
        if ($resource instanceof ItemRepresentation) {
            // S'il n'y a pas de fichier, c'est qu'il est non consultable de fait.
            $medias = $resource->media();
            if (!count($medias)) {
                return 'Non consultable';
            }
            foreach ($medias as $media) {
                $access = $media->value('curation:access')
                    ?? ($media->value('curation:reserved') ? 'Accès restreint' : 'Accès libre');
                $code = $this->classesAccess[(string) $access] ?? 'no-access';
                $levels[] = $this->accessLevels[$code];
            }
            $level = min($levels);
            return array_search(array_search($level, $this->accessLevels), $this->classesAccess)
                ?: 'Non consultable';
        }

        $access = $resource->value('curation:access')
            ?? ($resource->value('curation:reserved') ? 'Accès restreint' : 'Accès libre');
        $code = $this->classesAccess[(string) $access] ?? 'no-access';
        return array_search($code, $this->classesAccess)
            ?: 'Non consultable';
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
        return (bool) strpos($user->getEmail(), 'univ-tlse2.fr');
    }

    /**
     * Tous les médias doivent être listées, y compris les médias privés (quand
     * l'item est accessible), afin de pouvoir afficher l'information "non consultable".
     *
     * Pour les documents entièrement privés, il faut juste le titre.
     *
     * Néanmoins, ces fichiers sont mis en privé/réservé justement pour éviter cela.
     */
    public function danteMediaItem(ItemRepresentation $item): array
    {
        $medias = [];
        foreach ($item->media() as $media) {
            $medias[$media->id()] = $media;
        }

        $sql = <<<'SQL'
SELECT `resource`.`id`, `resource`.`title`
FROM `resource`
JOIN `media` ON `media`.`id` = `resource`.`id`
WHERE `media`.`item_id` = :item_id
    AND `resource`.`is_public` = 0
SQL;
        $privateMedias = $item->getServiceLocator()->get('Omeka\Connection')
            ->executeQuery($sql, ['item_id' => $item->id()])->fetchAllKeyValue();
        foreach ($privateMedias as $privateMediaId => $privateMediaLabel) {
            $medias[$privateMediaId] = $medias[$privateMediaId]
                ?? ['o:id' => $privateMediaId, 'o:title' => $privateMediaLabel];
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
        $value = $resource->value('dcterms:created');
        if ($value) {
            $year = substr((string) $value, 0, 4);
        } else {
            $year = 'sans date';
        }
        return $year;
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
       ?\AdvancedResourceTemplate\Api\Representation\ResourceTemplatePropertyRepresentation $templateProperty,
       ?string $metadata = null
    ) {
        if (!$templateProperty) {
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

    public function danteAdvancedTemplateValues(
        ?\Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource,
        array $values
    ): array {
        if (!$resource) {
            return $values;
        }
        $template = $resource->resourceTemplate();
        if (!$template) {
            return $values;
        }

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

        return $vals;
    }
}
