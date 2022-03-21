<?php declare(strict_types=1);

namespace OmekaTheme\Helper;

require_once __DIR__ . '/ThemeFunctionsSpecific.php';

use Laminas\View\Helper\AbstractHelper;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

class ThemeFunctions extends AbstractHelper
{
    use ThemeFunctionsSpecific;

    public function __invoke(): self
    {
        return $this;
    }

    /**
     * Get the Omeka services. It should not be used in themes.
     */
    public function getServiceLocator(): ?ServiceLocatorInterface
    {
        static $services;
        if (is_null($services)) {
            $site = $this->currentSite();
            if (!$site) {
                return null;
            }
            $services = $site->getServiceLocator();
        }
        return $services;
    }

    /**
     * Get the current site from the view or the root view (main layout).
     */
    public function currentSite(): ?\Omeka\Api\Representation\SiteRepresentation
    {
        return $this->view->site ?? $this->view->site = $this->view
            ->getHelperPluginManager()
            ->get(\Laminas\View\Helper\ViewModel::class)
            ->getRoot()
            ->getVariable('site');
    }

    /**
     * Add a value to the root view model.
     */
    public function appendVarToView($key, $value): self
    {
        $this->view
            ->getHelperPluginManager()
            ->get(\Laminas\View\Helper\ViewModel::class)
            ->getRoot()
            ->setVariable($key, $value);
        return $this;
    }

    /**
     * Add values to the root view model.
     */
    public function appendVarsToView($values): self
    {
        $rootModel = $this->view
            ->getHelperPluginManager()
            ->get(\Laminas\View\Helper\ViewModel::class)
            ->getRoot();
        foreach ($values as $key => $value) {
            $rootModel
                ->setVariable($key, $value);
        }
        return $this;
    }

    /**
     * Check if the current page is the home page (first page in main menu).
     */
    public function isHomePage(): bool
    {
        static $isHomePage;

        if (!is_null($isHomePage)) {
            return $isHomePage;
        }

        $site = $this->currentSite();
        if (empty($site)) {
            return $isHomePage = false;
        }

        $plugins = $this->view->getHelperPluginManager();
        $urlHelper = $plugins->get('url');
        $basePath = $plugins->get('basePath')->__invoke();

        // Check the alias of the root of Omeka S with rerouting.
        if ($this->isCurrentUrl($basePath)) {
            return $isHomePage = true;
        }

        // Adapted from \Omeka\Controller\Site::indexAction().
        $homepage = $site->homepage();
        if ($homepage) {
            $url = $urlHelper('site/page', [
                'site-slug' => $site->slug(),
                'page-slug' => $homepage->slug(),
            ]);
            if ($this->isCurrentUrl($url)) {
                return $isHomePage = true;
            }
        }

        // Check the first normal pages.
        $linkedPages = $site->linkedPages();
        if ($linkedPages) {
            $firstPage = current($linkedPages);
            $url = $urlHelper('site/page', [
                'site-slug' => $site->slug(),
                'page-slug' => $firstPage->slug(),
            ]);
            if ($this->isCurrentUrl($url)) {
                return $isHomePage = true;
            }
        }

        // Check the root of the site.
        $url = $urlHelper('site', ['site-slug' => $site->slug()]);
        if ($this->isCurrentUrl($url)) {
            return $isHomePage = true;
        }

        return $isHomePage = false;
    }

    /**
     * Check if the given URL matches the current request URL (without query).
     */
    public function isCurrentUrl(string $url): bool
    {
        static $currentUrl;
        static $stripOut;

        if (!strlen($url)) {
            return false;
        }

        if (is_null($currentUrl)) {
            // Adapted from Omeka Classic / globals.php.
            $currentUrl = $this->currentUrl();

            $plugins = $this->view->getHelperPluginManager();
            $serverUrl = $plugins->get('serverUrl')->__invoke();
            $basePath = $plugins->get('basePath')->__invoke();

            // Strip out the protocol, host, base URL, and rightmost slash before
            // comparing the URL to the current one
            $stripOut = [$serverUrl . $basePath, @$_SERVER['HTTP_HOST'], $basePath];
            $currentUrl = rtrim(str_replace($stripOut, '', $currentUrl), '/');
        }

        // Don't check if the url is part of the current url.
        $url = rtrim(str_replace($stripOut, '', $url), '/');
        return $url === $currentUrl;
    }

    /**
     * Get the current url.
     */
    public function currentUrl($absolute = false): string
    {
        static $currentUrl;
        static $absoluteCurrentUrl;

        if (is_null($currentUrl)) {
            $currentUrl = $this->view->url(null, [], true);
            $absoluteCurrentUrl = $this->view->serverUrl(true);
        }

        return $absolute
            ? $absoluteCurrentUrl
            : $currentUrl;
    }

    /**
     * Get the url to the main site (default site).
     */
    public function mainSiteUrl($absolute = false): string
    {
        $defaultSite = (int) $this->view->setting('default_site');
        if (!$defaultSite) {
            return $this->currentSite()->url(null, $absolute);
        }
        try {
            $defaultSite = $this->view->api()->read('sites', ['id' => $defaultSite])->getContent();
            return $defaultSite->url(null, $absolute);
        } catch (\Omeka\Mvc\Exception\RuntimeException $e) {
            return $this->currentSite()->url(null, $absolute);
        }
    }

    /**
     * Check if a module is active.
     */
    public function isModuleActive(string $module): bool
    {
        static $activeModules;
        if (is_null($activeModules)) {
            $activeModules = $this->getServiceLocator()->get('Omeka\Connection')
                ->fetchFirstColumn('SELECT id FROM module WHERE is_active = 1 ORDER BY id;');
        }
        return in_array($module, $activeModules);
    }

    public function hasMappingOrMarkers(?int $siteId = null): bool
    {
        static $hasMappingInAllSites;
        static $results = [];

        if (is_null($hasMappingInAllSites)) {
            $hasMappingInAllSites = $this->isModuleActive('Mapping');
            if (!$hasMappingInAllSites) {
                return false;
            }
            $api = $this->view->plugin('api');
            $mapping = $api->search('mappings', ['limit' => 1])->getTotalResults();
            $markers = $api->search('mapping_markers', ['limit' => 1])->getTotalResults();
            $hasMappingInAllSites = $results[$siteId] = ($mapping + $markers) > 0;
        }

        if (!$hasMappingInAllSites || empty($siteId)) {
            return $hasMappingInAllSites;
        }

        if (!isset($results[$siteId])) {
            $mapping = $api->search('mappings', ['site_id' => $siteId, 'limit' => 1])->getTotalResults();
            $markers = $api->search('mapping_markers', ['site_id' => $siteId, 'limit' => 1])->getTotalResults();
            $results[$siteId] = ($mapping + $markers) > 0;
        }

        return $results[$siteId];
    }

    public function hasResourceMappingOrMarkers(AbstractResourceEntityRepresentation $resource = null, ?int $siteId = null): bool
    {
        static $results = [];
        static $api;

        $result = $this->hasMappingOrMarkers($siteId);
        if (!$result) {
            return false;
        }

        if (is_null($api)) {
            $api = $this->view->plugin('api');
        }

        // To simplify process (adapter check "is_numeric").
        if (empty($siteId)) {
            $siteId = '';
        }

        $resourceId = $resource->id();
        if (!isset($results[$siteId][$resourceId])) {
            if ($resource instanceof \Omeka\Api\Representation\ItemRepresentation) {
                $mapping = $api->search('mappings', ['site_id' => $siteId, 'item_id' => $resourceId, 'limit' => 1])->getTotalResults();
                $markers = $api->search('mapping_markers', ['site_id' => $siteId, 'item_id' => $resourceId, 'limit' => 1])->getTotalResults();
                $results[$siteId][$resourceId] = ($mapping + $markers) > 0;
            } elseif ($resource instanceof \Omeka\Api\Representation\MediaRepresentation) {
                $itemId = $resource->item()->id();
                $markers = $api->search('mapping_markers', ['site_id' => $siteId, 'item_id' => $itemId, 'media_id' => $resourceId, 'limit' => 1])->getTotalResults();
                $results[$siteId][$resourceId] = $markers > 0;
            } else {
                $results[$siteId][$resourceId] = false;
                return false;
            }
        }

        return $results[$siteId][$resourceId];
     }

    /**
     * Simple helper to determine the standard route params of the current page.
     *
     * The difficulty is related to the fact that the standards routes change
     * the controller and use "__CONTROLLER__".
     */
    public function simpleRoute(): array
    {
        static $simpleRoute;

        if (is_null($simpleRoute)) {
            $simpleRoute = [
                'controller' => '',
                'action' => '',
            ];
            $params = $this->view->params()->fromRoute();
            $standardController = $params['controller'] ?? false;
            $controller = $params['__CONTROLLER__'] ?? $standardController;
            if (!$controller) {
                return $simpleRoute;
            }
            $controller = strtolower($controller);
            $resources = [
                'item' => 'item',
                'itemset' => 'item-set',
                'item-set' => 'item-set',
                'media' => 'media',
                'page' => 'page',
                'omeka\controller\site\item' => 'item',
                'omeka\controller\site\itemset' => 'item-set',
                'omeka\controller\site\media' => 'media',
                'omeka\vontroller\site\page' => 'page',
                'advancedsearch\controller\indexcontroller' => 'advanced-search',
                'advanced-search\controller\indexcontroller' => 'advanced-search',
                'search\controller\indexcontroller' => 'search',
            ];
            if (isset($resources[$controller])) {
                $controller = $resources[$controller];
                // TODO Be more precise on action according to route (show/browse/search).
                $action = $params['action'] ?? '';
                // Manage exceptions.
                if (!empty($params['item-set-id'])) {
                    if ($controller === 'item') {
                        $controller = 'item-set';
                        $action = 'show';
                    } elseif ($controller === 'advanced-search') {
                        $controller = 'advanced-search';
                        $action = 'item-set';
                    } elseif ($controller === 'search') {
                        $controller = 'search';
                        $action = 'item-set';
                    }
                }
                $simpleRoute = [
                    'controller' => $controller,
                    'action' => $action,
                ];
            } elseif ($standardController === 'Guest\Controller\Site\GuestController') {
                $simpleRoute = [
                    'controller' => 'guest',
                    'action' => $params['action'] ?? 'me',
                    'route' => 'site/guest/guest'
                ];
            } elseif ($standardController === 'Contribute\Controller\Site\GuestBoard') {
                $simpleRoute = [
                    'controller' => 'guest',
                    'action' => 'contribution',
                    'route' => 'site/guest/contribution'
                ];
            } elseif ($standardController === 'Selection\Controller\Site\GuestBoard') {
                $simpleRoute = [
                    'controller' => 'guest',
                    'action' => 'selection',
                    'route' => 'site/guest/selection'
                ];
            }
        }

        return $simpleRoute;
    }

    /**
     * Add hidden input from the query.
     *
     * @deprecated Use queryToHiddenInputs (Omeka) or HiddenInputsFromFilteredQuery() (module Search).
     */
    public function inputHiddenFromQuery(array $query, array $skipKeys = []): string
    {
        $html = '';
        foreach (explode("\n", http_build_query($query, '', "\n")) as $nameValue) {
            if (!$nameValue) {
                continue;
            }
            [$name, $value] = explode('=', $nameValue, 2);
            $name = urldecode($name);
            if (is_null($value) || in_array($name, $skipKeys)) {
                continue;
            }
            $name = htmlspecialchars($name, ENT_COMPAT | ENT_HTML5);
            $value = htmlspecialchars(urldecode($value), ENT_COMPAT | ENT_HTML5);
            $html .= '<input type="hidden" name="' . $name . '" value="' . $value . '"' . "/>\n";
        }
        return $html;
    }

    /**
     * Convertit une value en html.
     *
     * @todo Le AsHtml() devrait suffire avec les événements.
     * @todo Utiliser les filtres values ou le partial resource-values.
     */
    public function simpleValueAsHtmlForTerm(ValueRepresentation $value, string $term, ?array $linkables = null): string
    {
        static $escape;
        if (is_null($escape)) {
            $escape = $this->view->plugin('escapeHtml');
        }

        $val = $this->simpleValueForTerm($value, $term);
        return $val['link'] ?? (string) $escape($val['value']);
    }

    /**
     * Convertit une value en valeur simple pour le html.
     */
    public function simpleValueForTerm(ValueRepresentation $value, string $term, ?array $linkables = null): array
    {
        static $escape;
        static $escapeAttr;
        static $escapedBaseQuery;
        static $siteSlug;

        if (is_null($escape)) {
            $plugins = $this->view->getHelperPluginManager();
            $url = $plugins->get('url');
            $escape = $plugins->get('escapeHtml');
            $escapeAttr = $plugins->get('escapeHtmlAttr');

            $siteSlug = $this->currentSite()->slug();
            // TODO Check and use clean url.
            // $useCleanUrl = $plugins->has('cleanUrl');

            // Avoid multiple useless calls to the helper url().
            $simpleRoute = $this->simpleRoute();
            if ($simpleRoute['controller'] === 'search') {
                $escapedBaseQuery = $escapeAttr($url(
                    // $this->currentSite()->getServiceLocator()->get('Application')->getMvcEvent()->getRouteMatch()->getMatchedRouteName(),
                    $plugins->get('status')->getRouteMatch()->getMatchedRouteName(),
                    ['controller' => 'search'],
                    // Old module Search.
                    // ['query' => ['text' => ['filters' => [['join' => 'and', 'field' => '__FIELD__', 'type' => 'eq', 'value' => '__VALUE__']]]]],
                    // Module Advanced Search.
                    ['query' => ['filter' => [['join' => 'and', 'field' => '__FIELD__', 'type' => 'eq', 'value' => '__VALUE__']]]],
                    true
                ));
            } else {
                $escapedBaseQuery = $escapeAttr($url(
                    'site/resource',
                    ['action' => 'browse', 'site-slug' => $siteSlug],
                    ['query' => ['property' => [['joiner' => 'and', 'property' => '__FIELD__', 'type' => 'eq', 'text' => '__VALUE__']]]],
                    true
                ));
            }
        }

        $escapedTerm = rawurlencode($term);

        /** @var \Omeka\Api\Representation\ValueRepresentation $value*/
        $valueType = $value->type();
        $val = [
            'class' => 'value',
            'link' => null,
            'value' => null,
            'lang' => $value->lang(),
        ];

        // Une ressource peut être gérée via custom vocab: son type n’est pas seulement "resource"…
        // $isResource = substr($valueType, 0, 8) === 'resource';
        $valueResource = $value->valueResource();
        if ($valueResource) {
            $val['class'] .= ' resource ' . $valueResource->resourceName();
            $val['link'] = $valueResource->link($valueResource->displayTitle());
        } elseif ($valueType === 'uri') {
            $val['class'] .= ' uri';
            $val['link'] = $value->asHtml();
        } else {
            // Pas d’autres classes pour les autres types de données.
            // TODO Le AsHtml() devrait suffire avec les événements.
            $vv = (string) $value->value();
            $uri = (string) $value->uri();
            // Value suggest, rights statement, etc.
            if (strlen($uri) && strlen($vv)) {
                // Lien de recherche interne.
                $val['link'] = sprintf(
                    '<a href="%s">%s</a>',
                    str_replace(['__FIELD__', '__VALUE__'], [$escapedTerm, rawurlencode($uri)], $escapedBaseQuery),
                    $escape($vv)
                );
            } elseif (strlen($uri)) {
                // Lien externe en l’absence de label, car sinon ce n’est pas clair.
                // TODO Ajouter _target blank.
                $val['class'] = ' uri';
                $val['link'] = $value->asHtml();
            } elseif (
                // Toujours liens.
                (is_null($linkables)
                    || in_array($term, $linkables)
                    || substr($valueType, 0, 11) === 'customvocab'
                )
                // Jamais liens.
                && !(substr($valueType, 0, 7) === 'numeric'
                    || in_array($valueType, ['numeric:timestamp', 'numeric:integer', 'numeric:interval', 'numeric:duration', 'html', 'xml', 'boolean', 'geometry:geography', 'geometry:geometry'])
                )
            ) {
                $val['link'] = sprintf(
                    '<a href="%s">%s</a>',
                    str_replace(['__FIELD__', '__VALUE__'], [$escapedTerm, rawurlencode($vv)], $escapedBaseQuery),
                    $escape($vv)
                );
            } else {
                // $val['value'] = $vv;
                $val['value'] = $value->asHtml();
            }
        }

        if (!$value->isPublic()) {
            $val['class'] .= ' private';
        }

        return $val;
    }

    /**
     * Convertit une valeur en recherche pour les rebonds.
     */
    public function browseValueForTerm(ValueRepresentation $value, string $termOrField, $lang = null): array
    {
        static $hasModuleAdvancedSearch;
        static $hasModuleSearchSolr;
        static $hyperlink;
        static $baseSearchUrl;
        static $baseSearchQuery;
        static $baseSearchQueryVr;
        static $siteSlug;

        if (is_null($hasModuleAdvancedSearch)) {
            $plugins = $this->view->getHelperPluginManager();
            $url = $plugins->get('url');
            $hyperlink = $plugins->get('hyperlink');

            $siteSlug = $this->currentSite()->slug();

            // Avoid multiple useless calls to the helper url().
            $hasModuleAdvancedSearch = $this->isModuleActive('AdvancedSearch');
            $hasModuleSearchSolr = $this->isModuleActive('SearchSolr');
            if ($hasModuleAdvancedSearch) {
                $baseSearchUrl = $this->view->searchingUrl();
                $baseSearchQuery = http_build_query(['filter' => [
                    ['join' => 'and', 'field' => '__FIELD__', 'type' => 'eq', 'value' => '__VALUE__'],
                ]]);
                $baseSearchQueryVr = http_build_query(['filter' => [
                    ['join' => 'and', 'field' => '__FIELD__', 'type' => 'eq', 'value' => '__VALUE__'],
                ]]);
            } else {
                $baseSearchUrl = $url('site/resource', ['site-slug' => $siteSlug, 'controller' => 'item', 'action' => 'browse'], true);
                $baseSearchQuery = http_build_query(['filter' => [
                    ['join' => 'and', 'term' => '__FIELD__', 'type' => 'eq', 'text' => '__VALUE__'],
                ]]);
                $baseSearchQueryVr = http_build_query(['filter' => [
                    ['join' => 'and', 'term' => '__FIELD__', 'type' => 'res', 'text' => '__VALUE__'],
                ]]);
            }
        }

        $val = [
            'class' => 'value',
            'lang' => $value->lang(),
            'value' => null,
            'url' => null,
            'link' => null,
        ];

        // Don't check type, but presence of value resource, uri, or literal in
        // order to manage all cases directly (resource, custom vocab, value
        // suggest, etc.

        // In most of the cases, the terms to search are indexed as multiple
        // strings ("_ss" in default config of Solr).
        if ($hasModuleSearchSolr && strpos($termOrField, ':')) {
            $termOrField = str_replace(':', '_', $termOrField) . '_ss';
        }

        if ($vr = $value->valueResource()) {
            $val['class'] .= ' resource ' . $vr->resourceName();
            $val['value'] = $vr->displayTitle(null, $lang);
            $val['url'] = $baseSearchUrl . '?'
                . str_replace(['__FIELD__', '__VALUE__'], [rawurlencode($termOrField), rawurlencode((string) $vr->id())], $baseSearchQueryVr);
        } elseif ($uri = $value->uri()) {
            $val['class'] .= ' uri';
            $val['value'] = $value->value() ?: $uri;
            $val['url'] = $baseSearchUrl . '?'
                . str_replace(['__FIELD__', '__VALUE__'], [rawurlencode($termOrField), rawurlencode($uri)], $baseSearchQuery);
        } else {
            $val['class'] .= ' literal';
            $val['value'] = $value->asHtml(null, $lang);
            $val['url'] = $baseSearchUrl . '?'
                . str_replace(['__FIELD__', '__VALUE__'], [rawurlencode($termOrField), rawurlencode($val['value'])], $baseSearchQuery);
        }
        $val['link'] = $hyperlink($val['value'], $val['url'], ['class' => $val['class']]);

        return $val;
    }

    /**
     * Escape a string, except if it is an html.
     *
     * Simplify management of methods displayTitle() and displayDescription(),
     * where the type of string is unknown.
     *
     * @uses strip_tags()
     * @todo Replace escapeIfNeeded() by an override of the plugin escape? With an argument?
     */
    public function escapeIfNeeded($string): string
    {
        static $escape;

        if (is_null($escape)) {
            $escape = $this->view->plugin('escapeHtml');
        }

        if (is_null($string)) {
            return '';
        }

        $string = (string) $string;
        $noTagString = strip_tags($string);
        return $string === $noTagString
            ? $escape($string)
            : $string;
    }

    /**
     * Get the description and the "see more" description as html.
     *
     * @param string[]|string $property Specific properties instead of default
     *   template description. Append null to use default description.
     */
    public function descriptionAndMore(AbstractResourceEntityRepresentation $resource, $property = null, $lang = null): array
    {
        if (empty($property)) {
            $properties = [null];
        } elseif (is_string($property)) {
            $properties = [$property];
        } else {
            $properties = $property;
        }
        foreach ($properties as $property) {
            if ($property) {
                $description = $resource->value($property, ['lang' => $lang ? [$lang, ''] : null]);
                if ($description) {
                    $description = $description->asHtml();
                }
            } else {
                $description = (string) $resource->displayDescription(null, $lang);
            }
            if ($description) {
                break;
            }
        }
        if (!$description) {
            return ['', ''];
        }

        $more = null;

        $descriptionMore = $this->view->themeSetting('description_more') ?: 'auto';
        switch ($descriptionMore) {
            case 'auto':
                if (mb_substr($description, 0, 1) !== '<') {
                    $v = explode(' ', $description);
                    if (count($v) > 100) {
                        $description = implode(' ', array_slice($v, 0, 100)) . '…';
                        $more = implode(' ', array_slice($v, 100));
                    }
                }
                break;

            case 'new_line':
                if (mb_substr($description, 0, 1) !== '<') {
                    $pos = mb_strpos($description, "\n");
                    if ($pos) {
                        $desc = $description;
                        $description = mb_substr($description, 0, $pos) . '…';
                        $more = mb_substr($desc, $pos + 1);
                    }
                }
                break;

            case 'second':
                if ($description) {
                    $template = $resource->resourceTemplate();
                    $term = $template && $template->descriptionProperty()
                        ? $template->descriptionProperty()->term()
                        : 'dcterms:description';
                    $v = $resource->value($term, ['all' => true]);
                    if (!empty($v[1])) {
                        $more = $v[1]->asHtml();
                    }
                }
                break;

            // Other cases are properties.
            default:
                $more = $resource->value($descriptionMore, ['lang' => $lang ? [$lang, ''] : null]);
                if ($more) {
                    $more = $more->asHtml();
                }
                if ($more === $description) {
                    $more = null;
                }
                break;
        }


        if ($description && mb_substr($description, 0, 1) !== '<') {
            $description = nl2br(trim($description));
        }
        if ($more && mb_substr($more, 0, 1) !== '<') {
            $more = nl2br(trim($more));
        }

        return [
            $description,
            $more,
        ];
    }

    /**
     * Get the class of the search engine.
     *
     * @return ?string May be "sql" or "solr".
     */
    public function typeSearchEngine(\Laminas\Form\Form $form): ?string
    {
        $searchPage = $form->getOption('search_config');
        if (!$searchPage) {
            return null;
        }
        $adapter = $searchPage->engine()->adapter();
        if (!$adapter) {
            return null;
        }
        $engines = [
            \AdvancedSearch\Querier\InternalQuerier::class => 'sql',
            \AdvancedSearch\Querier\NoopQuerier::class => null,
            \SearchSolr\Querier\SolariumQuerier::class => 'solr',
        ];
        return $engines[$adapter->getQuerierClass()] ?? null;
    }

    /**
     * Quelques options du thème sont codées en durs, mais le nom dépend du
     * moteur (sql ou solr).
     *
     * Les arguments Omeka ne sont pas pris en compte ici.
     *
     * @deprecated La correspondance des clés solr est désormais gérée dans Search.
     */
    public function searchFormSpecificFields(\Laminas\Form\Form $form): array
    {
        $searchEngine = $this->typeSearchEngine($form);
        if ($searchEngine === 'solr') {
            return [
                /*
                'dcterms:subject' => [
                    'join' => ['text[filters][999][join]' => 'and'],
                    'field' => ['text[filters][999][field]' => 'dcterms_subject_ss'],
                    'type' => ['text[filters][999][type]' => 'eq'],
                    'value' => ['text[filters][999][value]' => ''],
                ],
                */
                'dcterms:subject' => 'dcterms_subject_ss',
            ];
        } elseif ('sql') {
            return [
                'dcterms:subject' => 'dcterms:subject',
            ];
        /*
        } elseif ('omeka') {
            return [
                'subject' => [
                    'joiner' => ['property[999][joiner]' => 'and'],
                    'property' => ['property[999][property]' => 'dcterms:subject'],
                    'type' => ['property[999][type]' => 'eq'],
                    'text' => ['property[999][text]' => ''],
                ],
            ];
        */
        }
        return [];
    }

    /**
     * Prépare un formulaire de recherche pour séparer le formulaire de base et
     * le formulaire avancée pour simplifier l’affichage dans certaines
     * configurations lorsque le formulaire avancé est lié au formulaire de base.
     *
     * Cela ne gère qu’un seul formulaire de recherche par site.
     *
     * @todo Simplifier en utilisant l'élément principal qui est toujours "q" (ou "fulltext_search"?).
     *
     * @return array
     */
    public function explodeSearchForm(\Laminas\Form\Form $form): array
    {
        static $result;

        if ($result) {
            return $result;
        }

        $view = $this->getView();

        $query = $view->params()->fromQuery() ?: [];
        $form
            ->setData($query)
            ->prepare();

        $hasOtherElements = false;
        $baseForm = clone $form;
        $formOtherElements = clone $form;
        $main = null;

        foreach ($form->getElements() as $element) {
            if ($element instanceof \Laminas\Form\Element\Csrf || $element instanceof \Laminas\Form\Element\Hidden) {
                $formOtherElements->remove($element->getName());
                continue;
            }
            // Éléments non principaux.
            if ($main) {
                if ($element->getOption('required')) {
                    $formOtherElements->remove($element->getName());
                } else {
                    $hasOtherElements = true;
                    $baseForm->remove($element->getName());
                }
            }
            // Champ de recherche principal.
            else {
                $main = $element;
                // Selon que l'on souhaite conserver l'élément principal ou non.
                // L'élément principal est disponible séparément.
                $formOtherElements->remove($element->getName());
            }
        }

        foreach ($form->getFieldsets() as $fieldset) {
            $hasOtherElements = true;
            $baseForm->remove($fieldset->getName());
        }

        $result = [
            'form' => $form,
            'baseForm' => $baseForm,
            'advancedForm' => $hasOtherElements ? $formOtherElements : null,
            'mainElement' => $main,
        ];
        return $result;
    }

    public function mainElementOfSearchForm(\Laminas\Form\Form $form): \Laminas\Form\Element
    {
        $result = $this->explodeSearchForm($form);
        return $result['mainElement'];
    }

    /**
     * This is the second element, whatever it is (except csrf or hidden).
     */
    public function secondElementOfSearchForm(\Laminas\Form\Form $form, ?string $type = null): ?\Laminas\Form\Element
    {
        $first = true;
        foreach ($form as $element) {
            if ($first) {
                $first = false;
            } elseif ($type && $element instanceof $type) {
                return $element;
            } elseif ($type && $element instanceof \Laminas\Form\Fieldset) {
                // No check of sub fieldset: normally none with AdvancedSearch.
                foreach ($element as $subElement) {
                    if ($subElement instanceof $type) {
                        return $subElement;
                    }
                }
            } elseif (!$type && !$element instanceof \Laminas\Form\Element\Csrf && !$element instanceof \Laminas\Form\Element\Hidden) {
                return $element;
            }
        }
        return null;
    }

    public function isAdvancedSearchForm(\Laminas\Form\Form $form): bool
    {
        $result = $this->explodeSearchForm($form);
        return (bool) $result['advancedForm'];
    }

    /**
     * Get the main viewer for a resource when a viewer exists.
     *
     * All media are renderable natively, but in some cases a viewer is used,
     * mainly for iiif. The lightGallery is available too.
     *
     * The choice takes care of the standard site option "item media embed".
     * The difficulty is related to the fact that the first media is not always
     * the one that defines the main viewer and that the main viewer depends on
     * the main file.
     *
     * @todo Check image type formats (tiff, jpeg2000) and the same for audio, video, and models.
     * @todo Manage downloadable files, not only links.
     * @todo Create a module for lightGallery (with openseadragon too).
     *
     * @return array List of medias for the main viewer, and list of links.
     */
    public function mainViewerAndLinks(ItemRepresentation $item, bool $hasLightGallery = false): array
    {
        $plugins = $this->view->getHelperPluginManager();
        $siteSetting = $plugins->get('siteSetting');

        $viewing = $this->listMediasByViewer($item);

        $viewing['viewer'] = null;

        $viewing['item_media_embed'] = $siteSetting('item_media_embed', false);
        if (!$viewing['item_media_embed'] || empty($viewing['all'])) {
            $viewing['links'] = $viewing['all'];
            return $viewing;
        }

        // Get the main viewer for iiif.
        $hasIiifServer = $plugins->has('iiifManifest');
        if ($hasIiifServer) {
            $blocksDisposed = $siteSetting('blocksdisposition_item_show', []);
            $iiifViewer = null;
            if ($blocksDisposed) {
                $iiifViewer = in_array('Mirador', $blocksDisposed) && $plugins->has('mirador')
                ? 'mirador'
                    : (in_array('UniversalViewer', $blocksDisposed) && $plugins->has('universalViewer')
                        ? 'universalViewer'
                        : (in_array('Diva', $blocksDisposed) && $plugins->has('diva')
                            ? 'diva'
                            : null));
            }
            if (!$iiifViewer) {
                $iiifViewer = $plugins->has('mirador')
                    ? 'mirador'
                        : ($plugins->has('universalViewer')
                            ? 'universalViewer'
                            : ($plugins->has('diva')
                                ? 'diva'
                                : null));
            }
        } else {
            $iiifViewer = null;
        }

        // Use the iiif viewer when at least one file is managed by the viewer.
        if ($iiifViewer === 'universalViewer') {
            if ($viewing['universalViewer']) {
                $viewing['viewer'] = 'universalViewer';
                $viewing['is_iiif'] = true;
                $viewing['links'] = array_diff_key($viewing['all'], $viewing['universalViewer']);
                return $viewing;
            }
        } elseif ($iiifViewer && count($viewing['iiifImage'])) {
            $viewing['viewer'] = $iiifViewer;
            $viewing['is_iiif'] = true;
            $viewing['links'] = array_diff_key($viewing['all'], $viewing['iiifImage']);
            return $viewing;
        }

        // LightGallery.
        if ($hasLightGallery) {
            $viewing['viewer'] = 'lightGallery';
            return $viewing;
        }

        // Renderable.
        // To manage: image, audio, video, html, pdf, office, model, iiif, score…
        return $viewing;
    }

    /**
     * Get the medias of an item according to media type, renderer and viewer.
     */
    public function listMediasByViewer(ItemRepresentation $item, ?string $viewer = null): array
    {
        $viewing = [
            // All non-to be viewed media. Always empty here: the viewer is unknown.
            'links' => [],

            // Allows to keep the original order with the media ids.
            'all' => [],

            // Standard files.
            'file' => [],

            // Standard files by media types.
            'media_types' => [],

            'image' => [],
            'audio' => [],
            'video' => [],
            // Standard html media.
            'html' => [],
            // A model may have one main file and many associated images or binary
            // files to compose a scene.
            'model' => [],
            // Pdf is a very common exception: can be rendered with some modules, or
            // be displayed with some other ones, or be downloaded.
            'pdf' => [],

            // Images directly viewable by a browser.
            'image_web' => [],

            // Mirador or Diva accepts only images currently.
            'iiifImage' => [],
            'universalViewer' => [],

            'lightGallery' => [],
            'lightGalleryIframe' => [],
        ];

        $itemMedias = $item->media();
        if (!count($itemMedias)) {
            return $viewing;
        }

        // TODO Check allowed audio and video types (but they can use alternatives inside html tag).
        $webImageTypes = [
            'image/bmp',
            'image/gif',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/svg+xml',
        ];

        foreach ($itemMedias as $media) {
            $mediaId = $media->id();
            $viewing['all'][$mediaId] = $media;
            $mediaRenderer = $media->renderer();
            if ($mediaRenderer === 'file') {
                $mediaType = $media->mediaType();
                $mediaTypeBase = strtok($mediaType, '/');
                $viewing['file'][$mediaId] = $media;
                $viewing['media_types'][$mediaType][$mediaId] = $media;
                if ($mediaTypeBase === 'image') {
                    $viewing['image'][$mediaId] = $media;
                    if (in_array($mediaType, $webImageTypes)) {
                        $viewing['image_web'][$mediaId] = $media;
                    }
                    $viewing['iiifImage'][$mediaId] = $media;
                    $viewing['universalViewer'][$mediaId] = $media;
                    $viewing['lightGallery'][$mediaId] = $media;
                } elseif ($mediaTypeBase === 'audio') {
                    $viewing['audio'][$mediaId] = $media;
                    $viewing['universalViewer'][$mediaId] = $media;
                    $viewing['lightGallery'][$mediaId] = $media;
                } elseif ($mediaTypeBase === 'video') {
                    $viewing['video'][$mediaId] = $media;
                    $viewing['universalViewer'][$mediaId] = $media;
                    $viewing['lightGallery'][$mediaId] = $media;
                } elseif ($mediaTypeBase === 'model') {
                    $viewing['model'][$mediaId] = $media;
                    $viewing['universalViewer'][$mediaId] = $media;
                    $viewing['lightGalleryIframe'][$mediaId] = $media;
                } elseif ($mediaType === 'application/pdf') {
                    $viewing['pdf'][$mediaId] = $media;
                    $viewing['universalViewer'][$mediaId] = $media;
                    $viewing['lightGallery'][$mediaId] = $media;
                    $viewing['lightGalleryIframe'][$mediaId] = $media;
                }
            } elseif ($mediaRenderer === 'iiif' || $mediaRenderer === 'tile') {
                $viewing['iiifImage'][$mediaId] = $media;
                $viewing['universalViewer'][$mediaId] = $media;
                $viewing['lightGallery'][$mediaId] = $media;
                $viewing['lightGalleryIframe'][$mediaId] = $media;
            } else {
                // 'html', 'oembed', 'youtube'.
                if ($mediaRenderer === 'html') {
                    $viewing['html'][$mediaId] = $media;
                }
                $viewing['lightGallery'][$mediaId] = $media;
                $viewing['lightGalleryIframe'][$mediaId] = $media;
            }
        }

        return $viewing;
    }

    /**
     * Return the html code for the light viewer with all medias.
     *
     * @todo Use a partial for light gallery.
     *
     * A div is added for videos.
     * @link https://sachinchoolur.github.io/lightGallery/demos/html5-videos.html
     * @todo Update to last version of light gallery for videos.
     * @link https://www.lightgalleryjs.com/demos/video-gallery/
     *
     * @todo Use iframe for other media.
     * An iframe is used for non-files, except youtube, natively supported. It
     * allows to support pdf too.
     * @link https://www.lightgalleryjs.com/demos/iframe/
     *
     * @return string Html code.
     */
    public function lightGallery(ItemRepresentation $item, array $options = []): string
    {
        if (empty($options['viewing'])) {
            $options['viewing'] = $this->mainViewerAndLinks($item, true);
        }
        $viewing = $options['viewing'];

        $plugins = $this->view->getHelperPluginManager();
        $escapeAttr = $plugins->get('escapeHtmlAttr');
        $viewerImageThumbnail = $plugins->get('themeSetting')->__invoke('viewer_image_thumbnail') ?: 'large';

        $html = '<ul id="itemfiles" class="media-list">';

        /** @var \Omeka\Api\Representation\MediaRepresentation $media */
        foreach ($viewing['lightGallery'] as $mediaId => $media) {
            $source = isset($viewing['image_web'][$mediaId])
                ? $media->thumbnailUrl($viewerImageThumbnail)
                : (isset($viewing['image'][$mediaId])
                    ? $media->thumbnailUrl('large')
                    : $media->originalUrl() ?? $media->source());

            $title = $media->displayTitle('');
            if (strlen($title)) {
                $titleEsc = $titleEsc = $escapeAttr($title);
                $titleTitle = ' alt="' . $titleEsc . '" title="' . $titleEsc . '"';
            } else {
                $titleTitle = '';
            }

            // Exception for video.
            // @link https://sachinchoolur.github.io/lightGallery/demos/html5-videos.html
            if (isset($viewing['video'][$mediaId])) {
                $thumb = $escapeAttr($media->thumbnailUrl('large'));
                $rendered = $escapeAttr('<div class="media-render">' . $media->render(['class' => 'lg-video-object lg-html5', 'preload' => 'none']) . '</div>');
                $html .= <<<HTML
<li data-html="$rendered" data-thumb="$thumb"$titleTitle data-poster="$thumb" class="media resource">
    <div class="media-render video">
        <a href="$source">
            <img src="$thumb">
        </a>
    </div>
</li>
HTML;
            } else {
                $thumb = $escapeAttr($media->thumbnailUrl('large'));
                // TODO Display image with iiif inside lightgallery when present.
                if (substr($media->mediaType(), 0, 5) === 'image') {
                    $rendered = <<<HTML
<div class="media-render image">
    <a href="$source">
        <img src="$thumb">
    </a>
</div>
HTML;
                } else {
                    $rendered = $media->render();
                }
                $html .= <<<HTML
<li data-src="$source" data-thumb="$thumb"$titleTitle class="media resource">
    $rendered
</li>
HTML;
            }
        }

        return $html
            . '</ul>';
    }

    public function shareLinks(?AbstractResourceEntityRepresentation $resource = null, ?string $title = null): array
    {
        $socialMedia = $this->view->themeSetting('social_media', ['email']);
        if (!$socialMedia) {
            return [];
        }

        $result = [];

        $translate = $this->view->plugin('translate');
        $site = $this->currentSite();
        $siteSlug = $site->slug();
        $siteTitle = $site->title();

        if ($resource) {
            $url = $resource->siteUrl($siteSlug, true);
            $title = $resource->displayTitle();
        } else {
            $url = $this->currentUrl(true);
            $title = $title ?: $siteTitle;
        }

        $encodedUrl = rawurlencode($url);
        $encodedTitle = rawurlencode($title);

        $onclick = "javascript:window.open(this.href, '', 'menubar=no,toolbar=no,resizable=yes,scrollbars=yes,height=300,width=600');return false;";
        foreach ($socialMedia as $social) {
            $attrs = [];
            switch ($social) {
                case 'facebook':
                    $attrs = [
                        'href' => 'https://www.facebook.com/sharer/sharer.php?u=' . $encodedUrl . '&t=' . $encodedTitle,
                        'title' => $translate('Partager sur Facebook'),
                        'onclick' => $onclick,
                        'target' => '_blank',
                        'class' => 'share-item icon-facebook',
                        'tabindex' => '0',
                    ];
                    break;
                case 'twitter':
                    $attrs = [
                        'href' => 'https://twitter.com/share?url=' . $encodedUrl . '&text=' . $encodedTitle,
                        'title' => $translate('Partager sur Twitter'),
                        'onclick' => $onclick,
                        'target' => '_blank',
                        'class' => 'share-item icon-twitter',
                        'tabindex' => '0',
                    ];
                    break;
                case 'email':
                    $attrs = [
                        'href' => 'mailto:?subject=' . $encodedTitle . '&body=' . rawurlencode(sprintf($translate("%s%s\n-\n%s"), $siteTitle, $title === $siteTitle ? '' : "\n-\n" . $title, $url)),
                        'title' => $translate('Partager par mail'),
                        'class' => 'share-item icon-mail',
                        'tabindex' => '0',
                    ];
                    break;
            }
            $result[$social] = $attrs;
        }
        return $result;
    }

    /**
     * @todo Keys are not checked, but this is only use internaly.
     */
    public function arrayToAttributes(array $attributes): string
    {
        $escapeAttr = $this->view->plugin('escapeHtmlAttr');
        return implode(' ', array_map(function($key) use ($attributes, $escapeAttr) {
            if (is_bool($attributes[$key])) {
                return $attributes[$key] ? $key . '="' . $key . '"' : '';
            }
            return $key . '="' . $escapeAttr($attributes[$key]) . '"';
        }, array_keys($attributes)));
    }

    /**
     * Get a site setting of any site.
     *
     * Core helpers $themeSetting() and $siteSetting() return current site args.
     */
    public function siteSetting($id, $default = null, $siteId = null)
    {
        if (!$siteId) {
            return $this->view->siteSetting($id, $default);
        }
        $siteSetting = $this->getServiceLocator()->get('Omeka\Settings\Site');
        $siteSetting->setTargetId($siteId);
        return $siteSetting->get($id, $default);
    }

    /**
     * Get a theme setting of any site.
     *
     * Core helpers $themeSetting() and $siteSetting() return current site args.
     *
     * The the site theme should be the same than the current one.
     */
    public function themeSetting($id, $default = null, $siteId = null)
    {
        if (!$siteId) {
            return $this->view->themeSetting($id, $default);
        }
        $currentTheme = $this->getServiceLocator()->get('Omeka\Site\ThemeManager')->getCurrentTheme();
        $themeSettings = $this->siteSetting($currentTheme->getSettingsKey(), false, $siteId);
        if (!$themeSettings) {
            return $default;
        }
        return $themeSettings[$id] ?? $default;
    }

    /**
     * Get the list of locales of the top page of the sites.
     *
     * Only the top page is available for relation.
     * Replace the module Internationalisation when missing.
     *
     * @see \Internationalisation\View\Helper\LanguageSwitcher
     */
    public function localeTopPages(): array
    {
        $data = [];
        $languageLists = $this->languageLists();
        $url = $this->view->plugin('url');
        foreach (reset($languageLists['site_groups']) as $siteSlug) {
            $localeId = $languageLists['locale_sites'][$siteSlug];
            $data[] = [
                'site' => $siteSlug,
                'locale' => $localeId,
                'locale_label' => $languageLists['locale_labels'][$localeId],
                'url' => $url('site', ['site-slug' => $siteSlug], [], false),
            ];
        }
        return $data;
    }

    /**
     * Get the list of locale of the sites.
     *
     * @see \Internationalisation\Service\ViewHelper\LanguageListFactory
     */
    public function localeSites(): array
    {
        return $this->languageLists('locale_sites');
    }

    /**
     * Get the list of locale labels.
     *
     * @see \Internationalisation\Service\ViewHelper\LanguageListFactory
     */
    public function localeLabels(): array
    {
        return $this->languageLists('locale_labels');
    }

    /**
     * Get the site groups from module internationalisation.
     *
     * @see \Internationalisation\Service\ViewHelper\LanguageListFactory
     */
    public function siteGroups(): array
    {
        return $this->languageLists('site_groups');
    }

    /**
     * Get a specific data about locales and sites.
     *
     * @see \Internationalisation\Service\ViewHelper\LanguageListFactory
     */
    public function languageLists(string $type = null): ?array
    {
        static $languageLists;

        if (is_null($languageLists)) {
            $user = $this->view->identity();
            $isPublic = !$user || $user->getRole() === 'guest';

            // Filter empty locale directly? Not here, in order to manage complex cases.
            $sql = <<<'SQL'
SELECT
    site.slug AS site_slug,
    REPLACE(site_setting.value, '"', "") AS localeId
FROM site_setting
JOIN site ON site.id = site_setting.site_id
WHERE site_setting.id = :setting_id
ORDER BY site.id
SQL;
            $bind = ['setting_id' => 'locale'];
            if ($isPublic) {
                $sql .= ' AND site.is_public = :is_public';
                $bind['is_public'] = 1;
            }

            /** @var \Doctrine\DBAL\Connection $connection */
            $connection = $this->getServiceLocator()->get('Omeka\Connection');
            $localeSites = $connection->fetchAllKeyValue($sql, $bind);
            $localeSites = array_filter($localeSites);

            if (!$localeSites) {
                $localeSites = [
                    $this->currentSite()->slug() => $this->view->setting('locale'),
                ];
            }

            // TODO Use laminas/doctrine language management.
            if (extension_loaded('intl')) {
                $localeLabels = [];
                foreach ($localeSites as $localeId) {
                    $localeLabels[$localeId] = \Locale::getDisplayName($localeId, $localeId);
                }
            } else {
                $localeLabels = array_combine($localeSites, $localeSites);
            }

            $siteGroups = [key($localeSites) => array_keys($localeSites)];

            $languageLists = [
                'locale_sites' => $localeSites,
                'locale_labels' => $localeLabels,
                'site_groups' => $siteGroups,
            ];
        }

        return $type
            ? $languageLists[$type] ?? null
            : $languageLists;
    }

    /**
     * Get the standard Rights Statements code from a uri (recommended) or a code.
     *
     * Note: the official uris use scheme "http:", not "https:". For a simpler
     * web browsing, the https is used in the output (url, not uri).
     *
     * @link https://rightsstatements.org/en/documentation/
     * @link https://rightsstatements.org/en/statements/
     * @link https://rightsstatements.org/en/documentation/usage_guidelines.html
     *
     * This is a generic theme replacement to the module RightsStatements.
     * Main differences are the possibility to query on uri, the use of codes
     * and the distinction between uri and url.
     *
     * @link https://github.com/zerocrates/RightsStatements
     */
    public function rightsStatements(
        ?AbstractResourceEntityRepresentation $resource,
        ?string $term = 'dcterms:rights',
        ?string $codeOrUrl = null,
        ?string $output = null,
        array $options = []
    ) {
        // This table is available as rdf.
        // Other codes are not standard and not supported.
        $data = [
            'InC' => [
                'code' => 'InC',
                'url' => 'https://rightsstatements.org/vocab/InC/1.0/',
                'label' => 'In Copyright',
                'title' => 'Protégé par le droit d’auteur',
                'summary' => 'La présente Déclaration des Droits peut être utilisée pour un Objet protégé par le droit d’auteur. L’utilisation de la présente Déclaration implique que l’organisme qui rend l’Objet accessible ait constaté que celui-ci est protégé par le droit d’auteur et que, soit il est le titulaire des droits sur l’Objet, soit il a obtenu du/des titulaire(s) des droits l’autorisation de rendre accessible(s) son/ses/leur(s) œuvre(s) ou alors il rend l’Objet accessible en vertu d’une exception ou d’une limitation au droit d’auteur (y compris le “Fair use”) qui l’autorise à rendre l’Objet accessible.',
            ],
            'InC-RUU' => [
                'code' => 'InC-RUU',
                'url' => 'https://rightsstatements.org/vocab/InC-RUU/1.0/',
                'label' => 'In Copyright - Rights-holder(s) Unlocatable or Unidentifiable',
                'title' => 'Protégé par le droit d’auteur - Titulaire(s) des droits impossible(s) à localiser ou à identifier',
                'summary' => 'La présente Déclaration des Droits est destinée à être utilisée pour un Objet identifié comme étant protégé par le droit d’auteur, mais pour lequel aucun titulaire des droits n’a pu être identifié ou localisé au terme de recherches raisonnables. La présente Déclaration des Droits ne doit être utilisée que si l’organisme envisageant de rendre l’Objet accessible a la certitude raisonnable que l’Œuvre sous-jacente est protégée par le droit d’auteur. Cette Déclaration des Droits n’est pas destinée à être utilisée par les organismes basés dans l’UE qui ont identifié des Œuvres comme étant des Œuvres Orphelines conformément à la Directive Européenne sur les Œuvres Orphelines (elles doivent plutôt utiliser la Déclaration InC-OW-EU).',
            ],
            'InC-NC' => [
                'code' => 'InC-NC',
                'url' => 'https://rightsstatements.org/vocab/InC-NC/1.0/',
                'label' => 'In Copyright - Non-Commercial Use Permitted',
                'title' => 'Protégé par le droit d’auteur - Utilisation non commerciale autorisée',
                'summary' => 'La présente Déclaration des Droits peut uniquement être utilisée pour les Objets protégés par le droit d’auteur. De plus, l’organisme qui les rend accessibles doit être titulaire des droits ou avoir été explicitement autorisé par le(s) titulaire(s) des droits à permettre à des tiers d’utiliser son/ses/leur(s) œuvre(s) à des fins pédagogiques sans autorisation préalable.',
            ],
            'InC-EDU' => [
                'code' => 'InC-EDU',
                'url' => 'https://rightsstatements.org/vocab/InC-EDU/1.0/',
                'label' => 'In Copyright - Educational Use Permitted',
                'title' => 'Protégé par le droit d’auteur - Utilisation à des fins pédagogiques autorisée',
                'summary' => 'La présente Déclaration des Droits peut uniquement être utilisée pour les Objets protégés par le droit d’auteur. De plus, l’organisme qui les rend accessibles doit être titulaire des droits ou avoir été explicitement autorisé par le(s) titulaire(s) des droits à permettre à des tiers d’utiliser son/ses/leur(s) œuvre(s) à des fins pédagogiques sans autorisation préalable.',
            ],
            'InC-OW-EU' => [
                'code' => 'InC-OW-EU',
                'url' => 'https://rightsstatements.org/vocab/InC-OW-EU/1.0/',
                'label' => 'In Copyright - EU Orphan Work',
                'title' => 'Protégé par le droit d’auteur - Œuvre Orpheline de l’UE',
                'summary' => 'La présente Déclaration des Droits est destinée à être utilisée pour les Objets dont l’œuvre sous-jacente a été identifiée comme Œuvre Orpheline, conformément à la Directive 2012/28/UE du Parlement Européen et du Conseil du 25 octobre 2012 sur certaines utilisations autorisées des Œuvres Orphelines. Elle ne peut être utilisée que pour des Objets dérivés d’Œuvres couvertes par la Directive : il peut s’agir d’Œuvres publiées sous forme de livres, de revues, de journaux, de magazines ou d’autres écrits, d’œuvres cinématographiques ou audiovisuelles et de phonogrammes (attention : la photographie et les arts visuels sont exclus). Cette Déclaration peut uniquement être utilisée par des organismes bénéficiaires de la Directive : les bibliothèques, les établissements d’enseignement et les musées accessibles au public, les archives, les institutions dépositaires du patrimoine cinématographique ou sonore et les organismes de radiodiffusion de service public, établis dans l’un des États membres de l’UE. En outre, le bénéficiaire doit avoir enregistré l’Œuvre dans la Base de données des Œuvres Orphelines de l’UE gérée par l’EUIPO.',
            ],
            'NoC-OKLR' => [
                'code' => 'NoC-OKLR',
                'url' => 'https://rightsstatements.org/vocab/NoC-OKLR/1.0/',
                'label' => 'No Copyright - Other Known Legal Restrictions',
                'title' => 'Absence de protection par le droit d’auteur - Autres restrictions juridiques connues',
                'summary' => 'Cette Déclaration des Droits devrait être utilisée pour les Objets qui sont dans le Domaine Public, mais dont la libre réutilisation est soumise à des restrictions juridiques connues, liées par exemple à la protection du patrimoine culturel ou des expressions culturelles traditionnelles. Ainsi, l’organisme qui entend rendre l’Objet accessible ne pourra pas autoriser sa libre réutilisation. Pour que la présente Déclaration des Droits soit probante, l’organisme qui entend rendre l’Objet accessible doit fournir un lien vers une page spécifiant les restrictions juridiques limitant la réutilisation de l’Objet.',
            ],
            'NoC-CR' => [
                'code' => 'NoC-CR',
                'url' => 'https://rightsstatements.org/vocab/NoC-CR/1.0/',
                'label' => 'No Copyright - Contractual Restrictions',
                'title' => 'Absence de protection par le droit d’auteur - Restrictions contractuelles',
                'summary' => 'L’utilisation de cette Déclaration des Droits est limitée aux objets qui sont dans le Domaine Public, mais pour lesquels l’organisme qui entend rendre l’Objet accessible a conclu une convention qui l’oblige à imposer des restrictions quant à l’utilisation de l’Objet par des tiers. Pour que la présente Déclaration des Droits soit probante, l’organisme qui entend rendre l’Objet accessible doit fournir un lien vers une page spécifiant les restrictions contractuelles applicables à l’utilisation de l’Objet.',
            ],
            'NoC-NC' => [
                'code' => 'NoC-NC',
                'url' => 'https://rightsstatements.org/vocab/NoC-NC/1.0/',
                'label' => 'No Copyright - Non-Commercial Use Only',
                'title' => 'Absence de protection par le droit d’auteur - Utilisation à des fins uniquement non commerciales',
                'summary' => 'L’utilisation de cette Déclaration des Droits est limitée aux œuvres qui sont dans le Domaine Public et qui ont été numérisées au sein d’un partenariat public-privé dans le cadre duquel les parties contractantes ont convenu de limiter les utilisations commerciales de cette représentation numérique de l’Œuvre par des tiers. Spécialement élaborée afin de pouvoir inclure les œuvres numérisées dans le cadre des partenariats existant entre des bibliothèques européennes et Google, elle peut théoriquement être utilisée pour des Objets qui ont été numérisés dans le cadre de partenariats public-privé similaires.',
            ],
            'NoC-US' => [
                'code' => 'NoC-US',
                'url' => 'https://rightsstatements.org/vocab/NoC-US/1.0/',
                'label' => 'No Copyright - United States',
                'title' => 'Absence de protection par le droit d’auteur - États-Unis',
                'summary' => 'Cette Déclaration des Droits devrait être utilisée pour les Objets pour lesquels l’organisme qui entend rendre l’Objet accessible a conclu qu’ils étaient libres de droits selon la législation des États-Unis. La présente Déclaration des Droits ne devrait pas être utilisée pour les Œuvres Orphelines (qui sont présumées être protégées par le droit d’auteur) ou pour les objets pour lesquels l’organisme qui entend rendre accessible l’Objet n’a pas pris de mesures pour déterminer le statut de l’œuvre sous-jacente au vu du droit d’auteur.',
            ],
            'NKC' => [
                'code' => 'NKC',
                'url' => 'https://rightsstatements.org/vocab/NKC/1.0/',
                'label' => 'No Known Copyright',
                'title' => 'Absence de protection connue par le droit d’auteur',
                'summary' => 'Cette Déclaration des Droits doit être utilisée pour les Objets dont le statut en matière de droit d’auteur n’a pas été déterminé de manière définitive, mais pour lesquels l’organisme qui entend rendre l’Objet accessible a des motifs raisonnables de croire que l’Œuvre sous-jacente n’est plus couverte par le droit d’auteur ou des droits voisins. La présente Déclaration des Droits ne doit pas être utilisée pour les Œuvres Orphelines (qui sont présumées être protégées par le droit d’auteur) ou pour les Objets pour lesquels l’organisme qui entend rendre accessible l’Objet n’a pas pris de mesures pour déterminer le statut de l’œuvre sous-jacente en ce qui concerne le droit d’auteur.',
            ],
            'UND' => [
                'code' => 'UND',
                'url' => 'https://rightsstatements.org/vocab/UND/1.0/',
                'label' => 'Copyright Undetermined',
                'title' => 'Droit d’auteur indéterminé',
                'summary' => 'Cette Déclaration des Droits doit être utilisée pour les Objets dont le statut en matière de droit d’auteur est inconnu et pour lesquels l’organisme qui a rendu l’Objet accessible a pris des mesures (infructueuses) pour déterminer le statut de l’Œuvre sous-jacente au vu du droit d’auteur. En règle générale, cette Déclaration des Droits est utilisée lorsque l’organisme n’a pas connaissance de tous les éléments-clés nécessaires pour déterminer avec précision le statut de l’Objet en matière de droit d’auteur.',
            ],
            'CNE' => [
                'code' => 'CNE',
                'url' => 'https://rightsstatements.org/vocab/CNE/1.0/',
                'label' => 'Copyright Not Evaluated',
                'title' => 'Droit d’auteur non évalué',
                'summary' => 'Cette Déclaration des Droits devrait être utilisée pour les Objets dont le statut en matière de droit d’auteur est inconnu et pour lesquels l’organisme qui entend rendre l’Objet accessible n’a pas pris de mesures pour déterminer le statut de l’œuvre sous-jacente au vu du droit d’auteur.',
            ],
        ];

        $codes2Urls = array_column($data, 'url', 'code');
        $codes2Labels = array_column($data, 'label', 'code');
        $lowerCodes = array_change_key_case(array_combine(array_keys($codes2Urls), array_keys($codes2Urls)));

        $url = null;
        $code = null;
        $label = null;

        $checkCode = function ($input) use ($codes2Urls, $codes2Labels, $lowerCodes): ?string {
            $input = (string) $input;
            if (!strlen($input)) {
                return null;
            }
            $input = strtok($input, '?');
            $lowerInput = strtolower($input);
            if (isset($lowerCodes[$lowerInput])) {
                return $lowerCodes[$lowerInput];
            }
            $inputFix = str_replace(['https:', 'page', 'data'], ['http:', 'vocab', 'vocab'], $input);
            $code = array_search($inputFix, $codes2Urls);
            if ($code) {
                return $code;
            }
            $code = array_search($input, $codes2Labels);
            if ($code) {
                return $code;
            }
            return null;
        };

        if ($resource) {
            foreach ($resource->value($term, ['all' => true]) as $value) {
                $url = strtolower((string) $value->uri());
                $label = trim((string) $value->value());
                $code = null;
                if ($url) {
                    $url = str_replace(['http:', 'data', 'page'], ['https:', 'vocab', 'vocab'], $url);
                    if (substr($url, 0, 29) === 'https://rightsstatements.org/') {
                        break;
                    }
                    continue;
                }
                $labelFix = str_replace(['http:', 'data', 'page'], ['https:', 'vocab', 'vocab'], $label);
                if (substr($labelFix, 0, 29) === 'https://rightsstatements.org/') {
                    $url = $labelFix;
                    $label = null;
                    break;
                }
                $code = $checkCode($label);
                if ($code) {
                    break;
                }
            }
            if (empty($url) && empty($code)) {
                return null;
            }
        } elseif ($codeOrUrl) {
            $codeOrUrl = str_replace(['http:', 'data', 'page'], ['https:', 'vocab', 'vocab'], trim(strtok($codeOrUrl, '?')));
            if (substr($codeOrUrl, 0, 29) === 'https://rightsstatements.org/') {
                $url = $codeOrUrl;
            } else {
                $code = $checkCode($codeOrUrl);
                if (!$code) {
                    return null;
                }
            }
        } else {
            return null;
        }

        if ($url && $code) {
            // Nothing to do.
        } elseif ($url && empty($code)) {
            $regex = '~^https?://rightsstatements\.org/\w+/(inc-ow-eu|inc-edu|inc-nc|inc-ruu|inc|noc-cr|noc-nc|noc-oklr|noc-us|cne|und|nkc)(?:/?|/\d\.\d/?)$~';
            $code = preg_replace($regex, '$1', strtolower(strtok($url, '?')));
            if (!isset($lowerCodes[$code])) {
                return null;
            }
            $code = $lowerCodes[$code];
            $url = $codes2Urls[$code];
        } elseif ($code && empty($url)) {
            $code = strtolower($code);
            if (!isset($lowerCodes[$code])) {
                return null;
            }
            $code = $lowerCodes[$code];
            $url = $codes2Urls[$code];
        } else {
            return null;
        }

        // Fill icon only when useful.
        if ($output === 'icon') {
            return $this->rightsStatementsIcon($code, $options);
        }

        $outputData = array_replace([
            'code' => null,
            'uri' => str_replace('https:', 'http:', $url),
            'url' => null,
            'label' => null,
            'title' => null,
            'summary' => null,
            'icon' => null,
        ], $data[$code]);
        if (array_key_exists($output, $outputData)) {
            return $outputData[$output];
        }
        $outputData['icon'] = $this->rightsStatementsIcon($code, $options);
        return $outputData;
    }

    /**
     * Get the html code from a Rights Statements uri (recommended) or code.
     *
     * @link https://rightsstatements.org/en/documentation/assets.html.
     * @link https://rightsstatements.org/en/documentation/usage_guidelines.html
     */
    public function rightsStatementsIcon(
        string $code,
        array $options = []
    ): ?string {
        $defaultOptions = [
            'icon_type' =>  'button',
            'format' => 'png',
            'as_image' => false,
            'color'  => 'dark-white-interior',
        ];
        $options += $defaultOptions;

        $codes2Urls = [
            'InC' => 'https://rightsstatements.org/vocab/InC/1.0/',
            'InC-RUU' => 'https://rightsstatements.org/vocab/InC-RUU/1.0/',
            'InC-NC' => 'https://rightsstatements.org/vocab/InC-NC/1.0/',
            'InC-EDU' => 'https://rightsstatements.org/vocab/InC-EDU/1.0/',
            'InC-OW-EU' => 'https://rightsstatements.org/vocab/InC-OW-EU/1.0/',
            'NoC-OKLR' => 'https://rightsstatements.org/vocab/NoC-OKLR/1.0/',
            'NoC-CR' => 'https://rightsstatements.org/vocab/NoC-CR/1.0/',
            'NoC-NC' => 'https://rightsstatements.org/vocab/NoC-NC/1.0/',
            'NoC-US' => 'https://rightsstatements.org/vocab/NoC-US/1.0/',
            'NKC' => 'https://rightsstatements.org/vocab/NKC/1.0/',
            'UND' => 'https://rightsstatements.org/vocab/UND/1.0/',
            'CNE' => 'https://rightsstatements.org/vocab/CNE/1.0/',
        ];
        $lowerCodes = array_change_key_case(array_combine(array_keys($codes2Urls), array_keys($codes2Urls)));

        $lowerCode = strtolower($code);
        if (!isset($lowerCodes[$lowerCode])) {
            return null;
        }
        $code = $lowerCodes[$lowerCode];

        $codes2Labels = [
            'InC' => 'In Copyright',
            'InC-RUU' => 'In Copyright - Rights-holder(s) Unlocatable or Unidentifiable',
            'InC-NC' => 'In Copyright - Non-Commercial Use Permitted',
            'InC-EDU' => 'In Copyright - Educational Use Permitted',
            'InC-OW-EU' => 'In Copyright - EU Orphan Work',
            'NoC-OKLR' => 'No Copyright - Other Known Legal Restrictions',
            'NoC-CR' => 'No Copyright - Contractual Restrictions',
            'NoC-NC' => 'No Copyright - Non-Commercial Use Only',
            'NoC-US' => 'No Copyright - United States',
            'NKC' => 'No Known Copyright',
            'UND' => 'Copyright Undetermined',
            'CNE' => 'Copyright Not Evaluated',
        ];

        $codes2icons = [
            'InC' => 'InC',
            'InC-RUU' => 'InC-UNKNOWN',
            'InC-NC' => 'InC-NONCOMMERCIAL',
            'InC-EDU' => 'InC-EDUCATIONAL',
            'InC-OW-EU' => 'InC-EU-ORPHAN',
            'NoC-OKLR' => 'NoC-OTHER',
            'NoC-CR' => 'NoC-CONTRACTUAL',
            'NoC-NC' => 'NoC-NONCOMMERCIAL',
            'NoC-US' => 'NoC-US',
            'NKC' => 'Other-UNKNOWN',
            'UND' => 'Other-UNDETERMINED',
            'CNE' => 'Other-NOTEVALUATED',
        ];

        $url = $codes2Urls[$code];
        $label = $codes2Labels[$code];
        $format = strtolower((string) $options['format']) === 'svg' ? 'svg' : 'png';

        $iconTypes = [
            'button' => 'buttons',
            'buttons' => 'buttons',
            'icon' => 'icons',
            'icons' => 'icons',
        ];
        $iconType = $iconTypes[$options['icon_type']] ?? 'buttons';
        if ($iconType === 'icons') {
            $colors = [
                'dark',
                'dark-white-interior',
                'white',
            ];
            $filename = strtok($codes2icons[$code], '-') . '.Icon-Only';
        } else {
            $colors = [
                'dark',
                'dark-white-interior',
                'dark-white-interior-blue-type',
                'white',
            ];
            $filename = $codes2icons[$code];
        }

        $color = in_array($options['color'], $colors) ? $options['color'] : 'dark-white-interior';

        $assetUrl = $this->view->assetUrl("img/rights-statements/$iconType/$format/$filename.$color.$format");
        return $options['as_image']
            ? sprintf('<img src="%s" alt="%s"/>', $assetUrl, $label)
            : sprintf('<a href="%s" alt="%s" _target="blank"><img src="%s" alt="%s"/></a>', $url, $label, $assetUrl, $label);
    }

    /**
     * Get the standard Creative Commons code from a uri (recommended) or a
     * string (code "cc-xx-xx" or text).
     *
     * Support old uris and codes, versionned and ported or not.
     * Fix some inputs too and manage public domain.
     *
     * Note: the official uris use scheme "http:", not "https:". For a simpler
     * web browsing, the https is used in the output (url, not uri).
     *
     * @link https://creativecommons.org/ns
     * @link https://creativecommons.org/schema.rdf
     * @link https://creativecommons.org/publicdomain/mark/1.0/rdf
     * @link https://creativecommons.org/licenses/by-nc-sa/3.0/fr/legalcode
     *
     * @todo Check the license (some does not exists: missing "by", etc).
     */
    public function creativeCommons(
        ?AbstractResourceEntityRepresentation $resource,
        ?string $term = 'dcterms:license',
        ?string $codeOrUrl = null,
        ?string $output = null,
        array $options = []
    ): ?array {
        // This table is available as rdf.
        // Other codes are not standard and not supported.
        $data = [
            'publicdomain' => [
                'code' => 'publicdomain',
                'url' => 'https://creativecommons.org/publicdomain/mark/1.0/',
                'label' => 'Public Domain',
                'title' => 'Pas de droit d’auteur',
                'summary' => 'Cette œuvre a été identifiée comme libre de restrictions connues selon les lois du droits d’auteur, y compris tous les droits affiliés et connexes. Vous pouvez copier, modifier, distribuer et jouer l’œuvre, même à des fins commerciales, sans avoir besoin d’une permission.',
            ],
            'cc0' => [
                'code' => 'cc0',
                'url' => 'https://creativecommons.org/publicdomain/zero/1.0/',
                'label' => 'Public Domain Dedication', // 'CC0 1.0 Universal',
                'title' => 'Transfert dans le domaine public',
                'summary' => 'La personne qui a associé une œuvre à cet acte a dédié l’œuvre au domaine public en renonçant dans le monde entier à ses droits sur l’œuvre selon les lois sur le droit d’auteur, droit voisin et connexes, dans la mesure permise par la loi. Vous pouvez copier, modifier, distribuer et représenter l’œuvre, même à des fins commerciales, sans avoir besoin de demander l’autorisation.',
            ],
            'by' => [
                'code' => 'by',
                'url' => 'https://creativecommons.org/licenses/by/4.0/',
                'label' => 'Attribution',
                'title' => 'Attribution',
                'summary' => 'Vous devez créditer l’Œuvre, intégrer un lien vers la licence et indiquer si des modifications ont été effectuées à l’Œuvre. Vous devez indiquer ces informations par tous les moyens raisonnables, sans toutefois suggérer que l’Offrant vous soutient ou soutient la façon dont vous avez utilisé son Œuvre.',
            ],
            'by-sa' => [
                'code' => 'by-sa',
                'url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
                'label' => 'Attribution-ShareAlike',
                'title' => 'Attribution - Partage dans les mêmes conditions',
                'summary' => null,
            ],
            'by-nc' => [
                'code' => 'by-nc',
                'url' => 'https://creativecommons.org/licenses/by-nc/4.0/',
                'label' => 'Attribution-NonCommercial',
                'title' => 'Attribution - Pas d’utilisation commerciale',
                'summary' => null,
            ],
            'by-nc-sa' => [
                'code' => 'by-nc-sa',
                'url' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/',
                'label' => 'Attribution-NonCommercial-ShareAlike',
                'title' => 'Attribution - Pas d’utilisation commerciale - Partage dans les mêmes conditions',
                'summary' => null,
            ],
            'by-nd' => [
                'code' => 'by-nd',
                'url' => 'https://creativecommons.org/licenses/by-nd/4.0/',
                'label' => 'Attribution-NoDerivatives',
                'title' => 'Attribution - Pas de modifications',
                'summary' => null,
            ],
            'by-nc-nd' => [
                'code' => 'by-nc-nd',
                'url' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/',
                'label' => 'Attribution-NonCommercial-NoDerivatives',
                'title' => 'Attribution - Pas d’utilisation commerciale - Pas de modifications',
                'summary' => null,
            ],
        ];

        $codes2Urls = array_column($data, 'url', 'code');
        $codes2Labels = array_column($data, 'label', 'code');
        $lowerLabels = array_map('strtolower', $codes2Labels);

        $codes2UrlsNoVersions = [
            'publicdomain' => 'https://creativecommons.org/publicdomain/mark',
            'cc0' => 'https://creativecommons.org/publicdomain/zero',
            'by' => 'https://creativecommons.org/licenses/by',
            'by-sa' => 'https://creativecommons.org/licenses/by-sa',
            'by-nc' => 'https://creativecommons.org/licenses/by-nc',
            'by-nc-sa' => 'https://creativecommons.org/licenses/by-nc-sa',
            'by-nd' => 'https://creativecommons.org/licenses/by-nd',
            'by-nc-nd' => 'https://creativecommons.org/licenses/by-nc-nd',
        ];

        $url = null;
        $code = null;
        $label = null;

        $checkCode = function ($input) use ($codes2Urls, $codes2Labels, $lowerLabels): ?string {
            $input = (string) $input;
            if (!strlen($input)) {
                return null;
            }
            $inputLower = strtolower(strtok($input, '?'));
            $value = strtok($inputLower, ' ');
            if ($value === 'public domain' || $value === 'pd' || $value === 'public-domain') {
                return 'publicdomain';
            }
            if ($value === 'public domain dedication' || $value === 'cc0' || $value === 'zero' || $value === 'cc-zero') {
                return 'cc0';
            }
            $code = array_search(str_replace('http:', 'https', $value), $codes2Urls);
            if ($code) {
                return $code;
            }
            $code = array_search($input, $codes2Labels);
            if ($code) {
                return $code;
            }
            $code = array_search($inputLower, $lowerLabels);
            if ($code) {
                return $code;
            }
            $code = substr($value, 3);
            if (isset($codes2Urls[$code])) {
                return $code;
            }
            return null;
        };

        if ($resource) {
            foreach ($resource->value($term, ['all' => true]) as $value) {
                $url = strtolower((string) $value->uri());
                $label = trim((string) $value->value());
                $code = null;
                if ($url) {
                    $url = str_replace('http:', 'https:', strtok($url, '?'));
                    if (substr($url, 0, 28) === 'https://creativecommons.org/') {
                        break;
                    }
                    continue;
                }
                $labelHttps = str_replace('http:', 'https:', strtok($label, '?'));
                if (substr($labelHttps, 0, 28) === 'https://creativecommons.org/') {
                    $url = $labelHttps;
                    $label = null;
                    break;
                }
                $code = $checkCode($label);
                if ($code) {
                    break;
                }
            }
            if (empty($url) && empty($code)) {
                return null;
            }
        } elseif ($codeOrUrl) {
            $codeOrUrl = trim(strtolower(str_replace('http:', 'https:', strtok($codeOrUrl, '?'))));
            if (substr($codeOrUrl, 0, 28) === 'https://creativecommons.org/') {
                $url = $codeOrUrl;
            } else {
                $code = $checkCode($codeOrUrl);
                if (!$code) {
                    return null;
                }
            }
        } else {
            return null;
        }

        if ($url && $code) {
            // Nothing to do.
        } elseif ($url && empty($code)) {
            // Manage version, juridiction port, and missing "/".
            $regex = '~^(.*?)(?:/?|/\d\.\d/?|/\d\.\d/[a-z][a-z][a-z]?/?)$~';
            $noVersionUrl = preg_replace($regex, '$1', strtok($url, '?'));
            $code = array_search(str_replace('http:', 'https:', $noVersionUrl), $codes2UrlsNoVersions);
            if (!$code) {
                // Support some common direct codes in values.
                $fixes = [
                    'pd' => 'publicdomain',
                    'mark' => 'publicdomain',
                    'zero' => 'cc0',
                ];
                // Ordered codes by lenght, except "publicdomain".
                $orderedCodes = ['mark', 'zero', 'publicdomain', 'by-nc-nd', 'by-nc-sa', 'by-nc', 'by-nd', 'by-sa', 'cc0', 'by', 'pd'];
                foreach ($orderedCodes as $orderedCode) {
                    if (strpos($url, $orderedCode)) {
                        $code = $fixes[$orderedCode] ?? $orderedCode;
                        break;
                    }
                }
                if (!$code) {
                    return null;
                }
            }
        } elseif ($code && empty($url)) {
            $code = strtolower($code);
            if (!isset($codes2Urls[$code])) {
                return null;
            }
            $url = $codes2Urls[$code];
        } else {
            return null;
        }

        // Fill icon only when useful.
        if ($output === 'icon') {
            return $this->creativeCommonsIcon($code, $options);
        }

        if (($output === 'summary' || !isset($data[$code][$output]))
            && empty($data[$code]['summary'])
        ) {
            foreach (explode('-', $code) as $codePart) {
                $data[$code]['summary'] .= $this->creativeCommonsPart($codePart)['summary'] . "\n";
            }
            $data[$code]['summary'] = trim($data[$code]['summary']);
        }

        $outputData = array_replace([
            'code' => null,
            'uri' => str_replace('https:', 'http:', $url),
            'url' => null,
            'label' => null,
            'title' => null,
            'summary' => null,
            'icon' => null,
        ], $data[$code]);
        if (array_key_exists($output, $outputData)) {
            return $outputData[$output];
        }
        $outputData['icon'] = $this->creativeCommonsIcon($code, $options);
        return $outputData;
    }

    /**
     * Get the html code from a Creative Commons uri (recommended) or code ("cc-xx-xx").
     *
     * Icons are available on http://i.creativecommons.org/l/by-nc-sa/3.0/fr/88x31.png,
     * that redirects to https://licensebuttons.net/l/by-nc-sa/3.0/fr/88x31.png etc.
     *
     * @link https://licensebuttons.net/i/
     * @link https://creativecommons.org/about/downloads/
     */
    public function creativeCommonsIcon(
        string $code,
        array $options = []
    ): ?string {
        $defaultOptions = [
            'icon_type' => 'button',
            'format' => 'png',
            'as_image' => false,
            'currency' => 'dollar',
        ];
        $options += $defaultOptions;

        // Other codes are not standard and not supported.
        $codes2Urls = [
            'publicdomain' => 'https://creativecommons.org/publicdomain/mark/1.0/',
            'cc0' => 'https://creativecommons.org/publicdomain/zero/1.0/',
            'by' => 'https://creativecommons.org/licenses/by/4.0/',
            'by-sa' => 'https://creativecommons.org/licenses/by-sa/4.0/',
            'by-nc' => 'https://creativecommons.org/licenses/by-nc/4.0/',
            'by-nc-sa' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/',
            'by-nd' => 'https://creativecommons.org/licenses/by-nd/4.0/',
            'by-nc-nd' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/',
        ];
        if (!isset($codes2Urls[$code])) {
            return null;
        }

        $codes2Labels = [
            'publicdomain' => 'Public Domain',
            'cc0' => 'Public Domain Dedication', // 'CC0 1.0 Universal',
            'by' => 'Attribution',
            'by-sa' => 'Attribution-ShareAlike',
            'by-nc' => 'Attribution-NonCommercial',
            'by-nc-sa' => 'Attribution-NonCommercial-ShareAlike',
            'by-nd' => 'Attribution-NoDerivatives',
            'by-nc-nd' => 'Attribution-NonCommercial-NoDerivatives',
        ];

        $iconTypes = [
            'buttons' => '88x31',
            'button' => '88x31',
            'normal' => '88x31',
            'compact' => '80x15',
            'icon' => 'icons',
            '88x31' => '88x31',
            '80x15' => '80x15',
            'icons' => 'icons',
        ];

        $iconType = $iconTypes[$options['icon_type']] ?? '88x31';
        $format = strtolower((string) $options['format']) === 'svg' ? 'svg' : 'png';
        $translate = $this->view->plugin('translate');

        $url = $codes2Urls[$code];
        $label = $code === 'publicdomain'
            ? $translate('Public domain') // @translate
            : 'Creative Commons ' . $codes2Labels[$code];
        $html = $options['as_image']
            ? ''
            : sprintf('<a href="%s" _target="blank" rel="license noopener noreferrer" alt="%s">', $url, $label);

        if ($iconType === 'icons') {
            $codes2Parts = [
                'cc' => 'Creative Commons',
                'publicdomain' => 'Public Domain',
                'cc0' => 'Public Domain Dedication',
                'by' => 'Attribution',
                'sa' => 'ShareAlike',
                'nc' => 'NonCommercial',
                'nd' => 'NoDerivatives',
            ];

            $codes2icons = [
                'cc' => 'cc',
                // For public domain, icon is "pd" (deprecated "public-domain").
                'publicdomain' => 'pd',
                'public-domain' => 'pd',
                'pd' => 'pd',
                // For cc0, the part icon is "zero".
                'cc0' => 'zero',
                'zero' => 'zero',
                'by' => 'by',
                'sa' => 'sa',
                'nc' => 'nc',
                'nc-dollar' => 'nc',
                'nc-euro' => 'nc-eu',
                'nc-yen' => 'nc-jp',
                'nc-eu' => 'nc-eu',
                'nc-jp' => 'nc-jp',
                'nd' => 'nd',
            ];

            $html .= '<span>';
            $baseAsset = $this->view->assetUrl('img/creative-commons/icons/');
            $codes = array_filter(explode('-', $code));
            if (reset($codes) !== 'cc') {
                array_unshift($codes, 'cc');
            }
            foreach ($codes as $part) {
                if (!isset($codes2Parts[$part])) {
                    continue;
                }
                $partIcon = $part === 'nc' ? 'nc-' . $options['currency'] : $part;
                $html .= sprintf('<img src="%s" alt="%s"/>', $baseAsset . $codes2icons[$partIcon] . $format, $codes2Parts[$part]);
            }
            return $html
                . '</span>'
                . ($options['as_image'] ? '' : '</a>');
        }

        $currency = in_array($options['currency'], ['euro', 'eu']) && in_array($code, ['by-nc', 'by-nc-sa', 'by-nc-nd'])
           ? '.eu'
           : '';
       $filenames = [
            'publicdomain' => 'public-domain',
            'pd' => 'public-domain',
            'mark' => 'public-domain',
            'cc0' => 'cc-zero',
            'zero' => 'cc-zero',
        ];
        $filename = $filenames[$code] ?? $code;
        $assetUrl = $this->view->assetUrl("img/creative-commons/$iconType/$filename$currency.$format");
        return $html
            . sprintf('<img src="%s" alt="%s"/>', $assetUrl, $label)
            . ($options['as_image'] ? '' : '</a>');
    }

    public function creativeCommonsPart(string $code): array
    {
        $data = [
            'cc' => [
                'label' => 'Licence Creative Commons',
                'title' => 'Licence Creative Commons',
                'summary' => 'Les licences de droits d’auteur et les outils Creative Commons apportent un équilibre à l’intérieur du cadre traditionnel “tous droits réservés” créé par les lois sur le droit d’auteur. Nos outils donnent à tout le monde, du créateur individuel aux grandes entreprises et aux institutions publiques, des moyens simples standardisés d’accorder des permissions de droits d’auteur supplémentaires à leurs œuvres. La combinaison de nos outils et de nos utilisateurs est un fonds commun numérique vaste et en expansion, un espace commun de contenus pouvant être copiés, distribués, modifiés, remixés, et adaptés, le tout dans le cadre des lois sur le droit d’auteur.',
            ],
            'publicdomain' => [
                'label' => 'Public Domain',
                'title' => 'Pas de droit d’auteur',
                'summary' => 'Cette œuvre a été identifiée comme libre de restrictions connues selon les lois du droits d’auteur, y compris tous les droits affiliés et connexes. Vous pouvez copier, modifier, distribuer et jouer l’œuvre, même à des fins commerciales, sans avoir besoin d’une permission.',
            ],
            'cc0' => [
                'label' => 'Public Domain Dedication',
                'title' => 'Transfert dans le domaine public',
                'summary' => 'La personne qui a associé une œuvre à cet acte a dédié l’œuvre au domaine public en renonçant dans le monde entier à ses droits sur l’œuvre selon les lois sur le droit d’auteur, droit voisin et connexes, dans la mesure permise par la loi. Vous pouvez copier, modifier, distribuer et représenter l’œuvre, même à des fins commerciales, sans avoir besoin de demander l’autorisation.',
            ],
            'by' => [
                'label' => 'Attribution',
                'title' => 'Attribution',
                'summary' => 'Vous devez créditer l’Œuvre, intégrer un lien vers la licence et indiquer si des modifications ont été effectuées à l’Œuvre. Vous devez indiquer ces informations par tous les moyens raisonnables, sans toutefois suggérer que l’Offrant vous soutient ou soutient la façon dont vous avez utilisé son Œuvre.',
            ],
            'sa' => [
                'label' => 'Share Alike',
                'title' => 'Partage dans les mêmes conditions',
                'summary' => 'Dans le cas où vous effectuez un remix, que vous transformez, ou créez à partir du matériel composant l’Œuvre originale, vous devez diffuser l’Œuvre modifiée dans les même conditions, c’est-à-dire avec la même licence avec laquelle l’Œuvre originale a été diffusée.',
            ],
            'nc' => [
                'label' => 'Non Commercial',
                'title' => 'Pas d’utilisation commerciale',
                'summary' => 'Vous n’êtes pas autorisé à faire un usage commercial de cette Œuvre, tout ou partie du matériel la composant.',
            ],
            'nd' => [
                'label' => 'Non derivatives',
                'title' => 'Pas de modifications',
                'summary' => 'Dans le cas où vous effectuez un remix, que vous transformez, ou créez à partir du matériel composant l’Œuvre originale, vous nl’Œuvreêtes pas autorisé à distribuer ou mettre à disposition l’Œuvre modifiée.',
            ],
        ];
        return $data[$code] ?? [];
    }

    /**
     * Get all linked resources, with indirect linked resources, included
     * current resource.
     *
     * A resource may have linked resources, but the second resources may not be
     * linked to all linked resources of the first one, so the relations are
     * checked recursively.
     *
     * This is useful when relations are not reciprocal, like translations.
     *
     * The base item is set first, if not infinitely recursive, else it is the
     * source.
     *
     * @see \BulkImport\Processor\SpipProcessor::deepLinkedResources()
     *
     * @return AbstractResourceEntityRepresentation[] By resource id.
     */
    public function deepLinkedResources(?AbstractResourceEntityRepresentation $resource, string $propertyTerm, int $propertyId): array
    {
        if (!$resource) {
            return [];
        }

        $result = [$resource->id() => $resource];
        $iterator = null;
        $iterator = function (AbstractResourceEntityRepresentation $resource, $level = 0) use(&$iterator, &$result, $propertyTerm, $propertyId): void {
            if ($level > 100) {
                return;
            }
            $resourceId = $resource->id();
            foreach ($resource->value($propertyTerm, ['all' => true, 'type' => ['resource', 'resource:item']]) as $value) {
                $linkedResource = $value->valueResource();
                $linkedResourceId = $linkedResource->id();
                if ($linkedResourceId !== $resourceId && !isset($result[$linkedResourceId])) {
                    $result[$linkedResourceId] = $linkedResource;
                    $iterator($linkedResource, $level + 1);
                }
            }
            $values = $resource->subjectValues(null, null, $propertyId);
            foreach ($values[$propertyTerm] ?? [] as $value) {
                $linkedResource = $value->resource();
                $linkedResourceId = $linkedResource->id();
                if ($linkedResourceId !== $resourceId && !isset($result[$linkedResourceId])) {
                    $result[$linkedResourceId] = $linkedResource;
                    $iterator($linkedResource, $level + 1);
                }
            }
        };
        $iterator($resource);
        $base = $this->baseDeepLinkedResources($result, $propertyTerm);
        return $base
        ? array_replace([$base->id() => $base], $result)
        : $result;
    }

    /**
     * Get the only resource that is not linked to the other ones, so the base.
     *
     * When all the resources are recursive, return nothing.
     * For example, if all resources are translations of other resources, there
     * is no base.
     *
     * @see \BulkImport\Processor\SpipProcessor::baseDeepLinkedResources()
     */
    public function baseDeepLinkedResources(?array $resources, $propertyTerm): ?AbstractResourceEntityRepresentation
    {
        if (!$resources) {
            return null;
        }
        foreach ($resources as $resource) {
            $linkeds = $resource->value($propertyTerm, ['all' => true, 'type' => ['resource', 'resource:item']]);
            if (!count($linkeds)) {
                return $resource;
            }
            // Check if the resource is linked to itself, whatever other links.
            $resourceId = $resource->id();
            foreach ($linkeds as $linked) {
                if ($linked->valueResource()->id() === $resourceId) {
                    return $resource;
                }
            }
        }
        return null;
    }

    /**
     * Check if a resource, page or site is in the current site.
     */
    public function isInCurrentSite(\Omeka\Api\Representation\AbstractEntityRepresentation $resource): bool
    {
        static $currentSiteId;
        static $currentSiteItemSetIds;

        if (is_null($currentSiteId)) {
            $currentSite = $this->currentSite();
            $currentSiteId = $currentSite->id();
        }

        if ($resource instanceof \Omeka\Api\Representation\SiteRepresentation) {
            return $resource->id() !== $currentSiteId;
        } elseif ($resource instanceof \Omeka\Api\Representation\SitePageRepresentation) {
            return $resource->site()->id() !== $currentSiteId;
        } elseif ($resource instanceof \Omeka\Api\Representation\ItemSetRepresentation) {
            if (is_null($currentSiteItemSetIds)) {
                $currentSiteItemSetIds = [];
                foreach ($currentSite->siteItemSets() as $siteItemSet) {
                    $currentSiteItemSetIds[] = $siteItemSet->itemSet()->id();
                }
            }
            return in_array($resource->id(), $currentSiteItemSetIds);
        } elseif ($resource instanceof \Omeka\Api\Representation\MediaRepresentation) {
            $resource = $resource->item();
        } elseif (!($resource instanceof \Omeka\Api\Representation\ItemRepresentation)) {
            return false;
        }

        foreach ($resource->sites() as $resourceSite) {
            if ($resourceSite->id() === $currentSiteId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a resource belongs to another site and get it.
     *
     * Dnas le cas d'un sous-site, retourne au site en cours si la ressource y
     * est présente.
     * Dans le cas du site principal, l'option permet de renvoyer au site externe,
     * même si la ressource est aussi dans le site principal.
     */
    public function externalSite(\Omeka\Api\Representation\AbstractEntityRepresentation $resource, bool $mainSiteExternal = false): ?\Omeka\Api\Representation\SiteRepresentation
    {
        static $isMainSite;
        static $currentSiteId;

        if (is_null($currentSiteId)) {
            $isMainSite = !$this->view->themeSetting('is_sub_site', false);
            $currentSite = $this->currentSite();
            $currentSiteId = $currentSite->id();
        }

        if ($resource instanceof \Omeka\Api\Representation\SiteRepresentation) {
            return $resource->id() !== $currentSiteId ? $resource : null;
        } elseif ($resource instanceof \Omeka\Api\Representation\SitePageRepresentation) {
            $site = $resource->site();
            return $site->id() !== $currentSiteId ? $site : null;
        } elseif ($resource instanceof \Omeka\Api\Representation\ItemSetRepresentation) {
            // Below.
        } elseif ($resource instanceof \Omeka\Api\Representation\MediaRepresentation) {
            $resource = $resource->item();
        } elseif (!($resource instanceof \Omeka\Api\Representation\ItemRepresentation)) {
            return false;
        }

        $sites = $resource->sites();
        if ((!$isMainSite || !$mainSiteExternal) && isset($sites[$currentSiteId])) {
            return null;
        }
        unset($sites[$currentSiteId]);
        return reset($sites) ?: null;
    }

    public function elementListValue(\Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource, string $name)
    {
        static $lang;
        static $langValue;
        static $headingTerm;
        static $bodyTerm;
        static $elementBody;
        static $defaultTitle;
        static $listMultifields;
        static $multifields;

        if (is_null($defaultTitle)) {
            $plugins = $this->view->getHelperPluginManager();
            $translate = $plugins->get('translate');
            $siteSetting = $plugins->get('siteSetting');
            $themeSetting = $plugins->get('themeSetting');

            $filterLocale = (bool) $siteSetting('filter_locale_values');
            $siteLang = $plugins->get('lang')();
            $lang = $filterLocale ? $siteLang : null;
            $langValue = $filterLocale ? [$siteLang, ''] : null;

            $elementBody = $themeSetting('list_element_body', 'default');
            $headingTerm = $siteSetting('browse_heading_property_term');
            $bodyTerm = $elementBody === 'none' ? null : $siteSetting('browse_body_property_term');

            $listMultifields = $themeSetting('list_multifields', []);
            $multifields['heading'] = isset($listMultifields['heading']) ? array_filter(explode(' ', str_replace(',', ' ', $listMultifields['heading']))) : [];
            $multifields['heading'] = array_unique(array_merge([$headingTerm], $multifields['heading']));
            if ($elementBody === 'none') {
                $multifields['body'] = [];
            } else {
                $multifields['body'] = isset($listMultifields['body']) ? array_filter(explode(' ', str_replace(',', ' ', $listMultifields['body']))) : [];
                $multifields['body'] = array_unique(array_merge([$bodyTerm], $multifields['body']));
            }
            $multifields['date'] = isset($listMultifields['date']) ? array_filter(explode(' ', str_replace(',', ' ', $listMultifields['date']))) : [];

            $defaultTitle = $translate('[Untitled]');
        }

        switch ($name) {
            case 'heading':
                foreach ($multifields['heading'] as $term) {
                    $value = $resource->value($term, ['lang' => $langValue]);
                    if ($value) {
                        return $value;
                    }
                }
                return $resource->displayTitle($defaultTitle, $lang);

            case 'body':
                foreach ($multifields['body'] as $term) {
                    $value = $resource->value($term, ['lang' => $langValue]);
                    if ($value) {
                        return $value;
                    }
                }
                return $elementBody === 'default'
                    ? $resource->displayDescription(null, $lang)
                    : null;

            default:
                foreach ($multifields[$name] ?? [] as $term) {
                    $value = $resource->value($term, ['lang' => $langValue]);
                    if ($value) {
                        return $value;
                    }
                }
                return null;
        }
    }
}
