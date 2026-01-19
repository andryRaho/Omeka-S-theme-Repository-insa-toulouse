<?php declare(strict_types=1);

namespace OmekaTheme\Helper;

require_once __DIR__ . '/ThemeFunctionsDante.php';

use Contribute\Api\Representation\ContributionRepresentation;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;

trait ThemeFunctionsSpecific
{
    use ThemeFunctionsDante;

    /**
     * Correspondance entre les valeurs et le nom dans le module Access.
     * Pas de différence entre "protected" et "forbidden" dans le module Access.
     */
    protected $normalizedAccess = [
        'Accès libre' => 'free',
        'free' => 'free',
        'free-access' => 'free',
        'open' => 'free',
        'public' => 'free',

        'Accès restreint' => 'reserved',
        'limited' => 'reserved',
        'limited-access' => 'reserved',
        'reserved' => 'reserved',
        'restricted' => 'reserved',

        'Non consultable' => 'forbidden',
        'no-access' => 'forbidden',
        'none' => 'forbidden',
        'private' => 'forbidden',
        'protected' => 'forbidden',
        'forbidden' => 'forbidden',
    ];

    protected $accessToLabels = [
        'free' => 'Accès libre',
        'reserved' => 'Accès restreint',
        'protected' => 'Non consultable',
        'forbidden' => 'Non consultable',
    ];

    /**
     * @var \Omeka\Api\Representation\ValueRepresentation|string $resourceOrCode
     */
    public function accessNormalize($resourceOrCode): string
    {
        return is_object($resourceOrCode) && $resourceOrCode instanceof AbstractResourceEntityRepresentation
            ? $this->accessLevel($resourceOrCode)
            : ($this->normalizedAccess[(string) $resourceOrCode] ?? 'forbidden');
    }

    public function accessLevel(AbstractResourceEntityRepresentation $resource): string
    {
        $plugins = $this->view->getHelperPluginManager();

        $accessCheck = $this->view->themeSetting('access_check', 'auto');

        if (!in_array($accessCheck, ['module_access', 'curation_access', 'visibility'])) {
            if ($plugins->has('accessLevel')) {
                $accessCheck = 'module_access';
            } elseif ($resource->value('curation:access')) {
                $accessCheck = 'curation_access';
            } else {
                $accessCheck = 'visibility';
            }
        } elseif ($accessCheck === 'module_access' && !$plugins->has('accessLevel')) {
            $accessCheck = 'curation_access';
        }

        if ($accessCheck === 'module_access') {
            return $this->view->accessLevel($resource);
        } elseif ($accessCheck === 'curation_access' && $value = $resource->value('curation:access')) {
            return $this->normalizedAccess[(string) $value] ?? 'forbidden';
        } else {
            return $resource->isPublic() ? 'free' : 'forbidden';
        }
    }

    /**
     * @var \Omeka\Api\Representation\ValueRepresentation|string $resourceOrCode
     */
    public function accessLabel($resourceOrCode): string
    {
        $accessLevel = $this->accessNormalize($resourceOrCode);
        return $this->accessToLabels[$accessLevel];
    }

    /**
     * Extrait l'année d'une date.
     */
    public function year(AbstractResourceEntityRepresentation $resource, ?string $property = null): string
    {
        if (!$property) {
            $themeSetting = $this->view->plugin('themeSetting');
            $property = $themeSetting('item_date_main') ?: 'dcterms:created';
        }

        $value = $resource->value($property);
        if (!$value) {
            return 'sans date';
        }
        return $value->type() === 'numeric:timestamp'
            ? (string) (\NumericDataTypes\DataType\Interval::getDateTimeFromValue((string) $value)['year'] ?? '')
            : substr((string) $value->value(), 0, 4);
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
            } elseif ($nextAction === 'notice' || $nextAction === 'fichiers') {
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
        $val = $templateProperty->mainDataValueMetadata('settings', $metadata);
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
        // Avoid an infinite loop with a sommaire on multiple pages.
        static $usedPages = [];

        /** @var \Omeka\Api\Representation\SitePageRepresentation $page */
        $page = $this->currentPage();
        if (!$page && !$pageSlugs) {
            return [];
        }

        $pageSlugs = array_filter(array_map('trim', explode("\n", $pageSlugs)), 'strlen');

        if (!is_array($tags)) {
            $tags = [$tags];
        }

        if (!$pageSlugs) {
            return $this->extractTags($page, $tags);
        }

        $siteId = $this->currentSite()->id();

        $headers = [];
        $api = $this->view->api();
        foreach ($pageSlugs as $pageSlug) {
            if (isset($usedPages[$pageSlug])) {
                continue;
            }
            $usedPages[$pageSlug] = true;
            try {
                /** @var \Omeka\Api\Representation\SitePageRepresentation $sitePage */
                $sitePage = $api->read('site_pages', ['site' => $siteId, 'slug' => $pageSlug])->getContent();
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
            } catch (\Exception $e) {
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
            // TODO The "@" avoids the deprecation notice. Replace by html_entity_decode/htmlentities. Or see polyfill mbstring.
            $html = '<div>' . @mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8') . '</div>';
            @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOENT);
            /** @var \DOMElement $element */
            foreach ($tags as $tag) {
                foreach ($dom->getElementsByTagName($tag) ?: [] as $element) {
                    $content = strip_tags((string) $element->textContent);
                    $id = $this->slugify($content);
                    $element->setAttributeNode(new \DOMAttr('id', $id));
                }
            }
            $html = mb_substr((string) @$dom->saveHTML(), 5, -7);
        } catch (\Exception $e) {
            $html = mb_substr($html, 5, -7);
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
                && (
                    // Old Omeka S < 4.1.
                    strpos((string) $block->dataValue('template'), 'aside')
                    // New Omeka S.
                    || (method_exists($block, 'layoutDataValue')
                        && strpos((string) $block->layoutDataValue('template_name'), 'aside'))
                )
            ) {
                continue;
            }
            $html = $blockLayoutRender->render($block);
            // preg_match_all('~<' . $tag . ' [^>]*>(.*)</' . $tag . '>~', $html, $matches);
            $dom = new \DOMDocument('1.1', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            try {
                // TODO The "@" avoids the deprecation notice. Replace by html_entity_decode/htmlentities. Or see polyfill mbstring.
                $html = '<div>' . @mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8') . '</div>';
                @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOENT);
                /** @var \DOMElement $element */
                if (count($tags) === 1) {
                    $tag = reset($tags);
                    $level = (int) substr($tag, 1, 1);
                    foreach ($dom->getElementsByTagName($tag) ?: [] as $element) {
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
                    foreach ($dom->getElementsByTagName('*') ?: [] as $element) {
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
}
