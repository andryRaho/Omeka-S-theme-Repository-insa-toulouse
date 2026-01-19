<?php declare(strict_types=1);

namespace OmekaTheme\Helper;

require_once __DIR__ . '/ThemeFunctionsSpecific.php';

use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Requires the module Common for some functions.
 */
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
     *
     * @deprecated Integrated in Omeka S v4.1.
     */
    public function currentSite(): ?\Omeka\Api\Representation\SiteRepresentation
    {
        if (version_compare(\Omeka\Module::VERSION, '4.1.0', '>=')) {
            return $this->view->site ?? $this->view->site = $this->view->currentSite();
        }

        // Or $this->layout()->site
        return $this->view->site ?? $this->view->site = $this->view
            ->getHelperPluginManager()
            ->get(\Laminas\View\Helper\ViewModel::class)
            ->getRoot()
            ->getVariable('site');
    }

    /**
     * Get the current page (site page) from the view or the route.
     *
     * It may be useful when a block doesn't return it or the block.
     */
    public function currentPage(): ?\Omeka\Api\Representation\SitePageRepresentation
    {
        if ($this->view->page && $this->view->page instanceof \Omeka\Api\Representation\SitePageRepresentation) {
            return $this->view->page;
        }
        if ($this->view->block && $this->view->block instanceof \Omeka\Api\Representation\SitePageBlockRepresentation) {
            return $this->view->page = $this->view->block->page();
        }
        $pageSlug = $this->view->params()->fromRoute('page-slug');
        if (empty($pageSlug)) {
            return null;
        }
        $site = $this->currentSite();
        return $this->view->page = $this->view->api()->searchOne('site_pages', ['site_id' => $site->id(), 'slug' => $pageSlug])->getContent();
    }

    /**
     * Add a value to the root view model.
     */
    public function appendVarToRootView($key, $value): self
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
    public function appendVarsToRootView($values): self
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

    public function getVarFromRootView($key)
    {
        return $this->view
            ->getHelperPluginManager()
            ->get(\Laminas\View\Helper\ViewModel::class)
            ->getRoot()
            ->getVariable($key);
    }

    /**
     * Check if the current page is the home page (first page in main menu).
     *
     * @deprecated Use Common helper isHomePage() instead.
     */
    public function isHomePage(): bool
    {
        return $this->view->isHomePage();
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
        /** @var \Omeka\Api\Representation\SiteRepresentation $defaultSite */
        $site = $this->view->defaultSite()->__invoke() ?: $this->currentSite();
        return $site->url(null, $absolute);
    }

    /**
     * Check if a module is active and greater or equal to a version.
     *
     * @deprecated Just check for the presence of a view helper.
     */
    public function isModuleActive(string $module, ?string $minimumVersion = null): bool
    {
        static $activeModuleVersions;
        if (is_null($activeModuleVersions)) {
            /** @var \Doctrine\DBAL\Connection $connection */
            $connection = $this->getServiceLocator()->get('Omeka\Connection');
            $activeModuleVersions = $connection->fetchAllKeyValue('SELECT id, version FROM module WHERE is_active = 1 ORDER BY id ASC;');
        }
        if (!isset($activeModuleVersions[$module])) {
            return false;
        }
        return $minimumVersion
            ? version_compare($activeModuleVersions[$module], $minimumVersion, '>=')
            : true;
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
                'omeka\controller\site\page' => 'page',
                'advancedsearch\controller\searchcontroller' => 'advanced-search',
                'advanced-search\controller\searchcontroller' => 'advanced-search',
                'search\controller\indexcontroller' => 'search',
                // Deprecated.
                'advancedsearch\controller\indexcontroller' => 'advanced-search',
                'advanced-search\controller\indexcontroller' => 'advanced-search',
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
                    'route' => 'site/guest/guest',
                ];
            } elseif ($standardController === 'Contribute\Controller\Site\GuestBoard') {
                $simpleRoute = [
                    'controller' => 'guest',
                    'action' => 'contribution',
                    'route' => 'site/guest/contribution',
                ];
            } elseif ($standardController === 'Selection\Controller\Site\GuestBoard') {
                $simpleRoute = [
                    'controller' => 'guest',
                    'action' => 'selection',
                    'route' => 'site/guest/selection',
                ];
            }
        }

        return $simpleRoute;
    }

    public function navMenu(string $name): ?string
    {
        $menu = $this->view->themeSetting($name);
        if (!$menu) {
            return null;
        }

        $plugins = $this->view->getHelperPluginManager();
        $api = $plugins->get('api');

        $siteSlug = $this->currentSite()->slug();
        $baseSiteUrl = '/s/' . $siteSlug . '/';

        $isMenuString = !is_array($menu);
        if ($isMenuString) {
            $menu = $this->stringToList($menu);
        }

        $links = [];
        foreach ($menu as $urlOrSlug => $label) {
            if ($isMenuString) {
                [$urlOrSlug, $label] = array_map('trim', explode('=', $label, 2));
            } else {
                $urlOrSlug = (string) $urlOrSlug;
            }
            if (!strlen($urlOrSlug)) {
                continue;
            }
            $noLabel = $label === null || !strlen($label);
            // Pages.
            if (mb_substr($urlOrSlug, 0, 8) !== 'https://'
                && mb_substr($urlOrSlug, 0, 7) !== 'http://'
                && strpos($urlOrSlug, '/') === false
            ) {
                if ($noLabel) {
                    /** @var \Omeka\Api\Representation\SitePageRepresentation $page */
                    $page = $api->searchOne('site_pages', ['site_slug' => $siteSlug, 'slug' => $urlOrSlug])->getContent();
                    $label = $page ? $page->title() : $urlOrSlug;
                }
                $urlOrSlug = $baseSiteUrl . 'page/' . $urlOrSlug;
            } elseif ($noLabel) {
                $label = basename($urlOrSlug);
            }
            $links[$urlOrSlug] = $label;
        }

        if (!$links) {
            return '';
        }

        $escape = $plugins->get('escapeHtml');
        $escapeAttr = $plugins->get('escapeHtmlAttr');

        $html = '<ul>' . PHP_EOL;
        foreach ($links as $url => $label) {
            $html .= sprintf('<li><a href="%s">%s</a></li>', $escapeAttr($url), $escape($label)) . PHP_EOL;
        }
        $html .= '</ul>' . PHP_EOL;
        return $html;
    }

    /**
     * Transform the given string into a valid URL slug.
     *
     * @see \Omeka\Api\Adapter\SiteSlugTrait::slugify()
     */
    public function slugify($input): string
    {
        if (extension_loaded('intl')) {
            $transliterator = \Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;');
            $slug = $transliterator->transliterate((string) $input);
        } elseif (extension_loaded('iconv')) {
            $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $input);
        } else {
            $slug = (string) $input;
        }
        $slug = mb_strtolower($slug, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9-]+/u', '-', $slug);
        $slug = preg_replace('/-{2,}/', '-', $slug);
        $slug = preg_replace('/-*$/', '', $slug);
        return $slug;
    }

    /**
     * Get each line of a string separately.
     */
    public function stringToList($string): array
    {
        return array_filter(array_map('trim', explode("\n", $this->fixEndOfLine($string))), 'strlen');
    }

    /**
     * Clean the text area from end of lines.
     *
     * This method fixes Windows and Apple copy/paste from a textarea input.
     */
    public function fixEndOfLine($string): string
    {
        return str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], (string) $string);
    }

    /**
     * Add hidden input from the query.
     *
     * It is mainly used for the pagination, but may be used anywhere.
     *
     * @deprecated Use queryToHiddenInputs (Omeka) or HiddenInputsFromFilteredQuery() (module AdvancedSearch).
     * @see \Omeka\View\Helper\QueryToHiddenInputs
     * @see \AdvancedSearch\View\Helper\HiddenInputsFromFilteredQuery
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
     * Convertit une valeur en recherche pour les rebonds.
     *
     * @deprecated Use a filter on value. Integrated in module AdvancedResourceTemplate.
     * @see \AdvancedResourceTemplate\Module
     */
    public function browseValueForTerm(ValueRepresentation $value, string $termOrField, $lang = null): array
    {
        static $hasModuleAdvancedSearch;
        static $useSearchSolr;
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
            $hasModuleSearchSolr = $hasModuleAdvancedSearch && $this->isModuleActive('SearchSolr');
            /** @var \AdvancedSearch\Api\Representation\SearchConfigRepresentation $searchConfig */
            if ($hasModuleSearchSolr) {
                $searchConfig = $this->view->getSearchConfig();
                $searchEngine = $searchConfig ? $searchConfig->searchEngine() : null;
                $engineAdapter = $searchEngine ? $searchEngine->engineAdapter() : null;
                $useSearchSolr = $engineAdapter && $engineAdapter instanceof \SearchSolr\EngineAdapter\Solarium;
            } else {
                $useSearchSolr = false;
            }
            if ($useSearchSolr) {
                $baseSearchUrl = $this->view->searchingUrl();
                $baseSearchQuery = http_build_query(['filter' => [
                    ['join' => 'and', 'field' => '__FIELD__', 'type' => 'eq', 'val' => '__VALUE__'],
                ]]);
                $baseSearchQueryVr = $baseSearchQuery;
            } elseif ($hasModuleAdvancedSearch) {
                $baseSearchUrl = $this->view->searchingUrl();
                $baseSearchQuery = http_build_query(['filter' => [
                    ['join' => 'and', 'field' => '__FIELD__', 'type' => 'eq', 'val' => '__VALUE__'],
                ]]);
                $baseSearchQueryVr = http_build_query(['filter' => [
                    ['join' => 'or', 'field' => '__FIELD__', 'type' => 'res', 'val' => '__VALUE__'],
                ]]);
            } else {
                $baseSearchUrl = $url('site/resource', ['site-slug' => $siteSlug, 'controller' => 'item', 'action' => 'browse'], true);
                $baseSearchQuery = http_build_query(['property' => [
                    ['join' => 'and', 'property' => '__FIELD__', 'type' => 'eq', 'text' => '__VALUE__'],
                ]]);
                $baseSearchQueryVr = http_build_query(['filter' => [
                    ['join' => 'or', 'property' => '__FIELD__', 'type' => 'res', 'text' => '__VALUE__'],
                ]]);
            }
        }

        $val = [
            'v' => $value,
            'class' => 'value',
            'lang' => $value->lang(),
            'value' => null,
            'url' => null,
            'link' => null,
        ];

        // Manage AdvancedResourceTemplate terms.
        $originalTermOrField = $termOrField;
        $termOrField = strtok($originalTermOrField, '/');

        // Don't check type, but presence of value resource, uri, or literal in
        // order to manage all cases directly (resource, custom vocab, value
        // suggest, etc.

        // In most of the cases, the terms to search are indexed as multiple
        // strings ("_ss" in default config of Solr).
        if ($useSearchSolr && strpos($termOrField, ':')) {
            $termOrField = str_replace(':', '_', $termOrField) . '_ss';
        }

        if ($vr = $value->valueResource()) {
            $val['class'] .= ' resource ' . $vr->resourceName();
            $val['value'] = $vr->displayTitle(null, $lang);
            if ($useSearchSolr) {
                $val['url'] = $baseSearchUrl . '?'
                    . str_replace(['__FIELD__', '__VALUE__'], [rawurlencode($termOrField), rawurlencode((string) $val['value'])], $baseSearchQueryVr);
            } else {
                $val['url'] = $baseSearchUrl . '?'
                    . str_replace(['__FIELD__', '__VALUE__'], [rawurlencode($termOrField), rawurlencode((string) $vr->id())], $baseSearchQueryVr);
            }
        } elseif ($uri = $value->uri()) {
            $val['class'] .= ' uri';
            $val['value'] = (string) $value->value() ?: $uri;
            $val['url'] = $baseSearchUrl . '?'
                . str_replace(['__FIELD__', '__VALUE__'], [rawurlencode($termOrField), rawurlencode($uri)], $baseSearchQuery);
        } else {
            $val['class'] .= ' literal';
            // $val['value'] = $value->asHtml(null, $lang);
            $val['value'] = (string) $value->value();
            $val['url'] = $baseSearchUrl . '?'
                . str_replace(['__FIELD__', '__VALUE__'], [rawurlencode($termOrField), rawurlencode($val['value'])], $baseSearchQuery);
        }
        $val['link'] = $hyperlink($val['value'], $val['url'], ['class' => $val['class']]);

        return $val;
    }

    /**
     * Divide a text in two parts according to a mode or a setting.
     *
     * It allows to display a description or a long metadata with a button
     * "see more".
     *
     * @param string|string[] $property Specific properties instead of default
     *   template description. Append null to use the default description.
     * @param string $mode May be "nth" character (default), "new_line",
     *   "double_new_line", "second" value of the resource, "second_or_nth",
     *   "second_or_new_line", "second_or_double_new_line" or any property.
     * @return array The first and the second text parts.
     *
     * @todo Remove use of nl2br and use simplexml.
     */
    public function startAndMore($resourceOrText, $property = null, $lang = null, int $maxWords = 100, ?string $mode = null): array
    {
        $text = null;
        $resource = $resourceOrText instanceof AbstractResourceEntityRepresentation ? $resourceOrText : null;
        if ($resource) {
            if (empty($property)) {
                $properties = [null];
            } elseif (is_string($property)) {
                $properties = [$property];
            } else {
                $properties = $property;
            }
            foreach ($properties as $property) {
                if ($property) {
                    $value = $resource->value($property, ['lang' => $lang ? [$lang, ''] : null]);
                    if ($value) {
                        $text = $value->asHtml();
                    }
                } else {
                    $text = (string) $resource->displayDescription(null, $lang);
                }
                if (strlen($text)) {
                    break;
                }
            }
        } else {
            $text = (string) $resourceOrText;
        }
        if (!strlen((string) $text)) {
            return ['', ''];
        }

        $output = $text;
        $more = null;

        $mode = $mode ?? ($this->view->themeSetting('start_and_more_mode') ?: 'nth');
        switch ($mode) {
            case 'nth':
                // Experimental for html.
                if (mb_substr($text, 0, 1) === '<') {
                    // The simplest way, but imperfect, is to strip tags, then
                    // get the word at the 100th word, then find it in the
                    // original string.
                    // Possible issue: the word is short or a punctuation sign
                    // and already in the beginning of the text or it exists in
                    // attributes. So count the number of words before the found
                    // one.
                    // TODO Improve html cut at nth word.
                    $v = explode(' ', strip_tags($text));
                    if (count($v) > $maxWords) {
                        $word = $v[$maxWords];
                        $start = implode(' ', array_slice($v, 0, $maxWords));
                        $minPosChar = max($maxWords * 2, mb_strlen($start));
                        $minPosText = substr_count($start, $word);
                        if ($minPosText <= 1) {
                            $pos = mb_strpos($text, $word, $minPosChar) + mb_strlen($word);
                        } else {
                            for ($i = 0, $pos = 0, $len = 0; $i < $minPosText; $i++) {
                                $pos = mb_strpos($text, $word, $pos + $len);
                                if (!$i) {
                                    $len = mb_strlen($word);
                                }
                            }
                        }
                        $output = mb_substr($text, 0, $pos + 1) . '…';
                        $more = mb_substr($text, $pos + 1);
                    }
                } else {
                    $v = explode(' ', strip_tags($text));
                    if (count($v) > $maxWords) {
                        $output = implode(' ', array_slice($v, 0, $maxWords)) . '…';
                        $more = implode(' ', array_slice($v, $maxWords));
                    }
                }
                break;

            case 'new_line':
                if (mb_substr($text, 0, 1) === '<') {
                    // A end p, div or br followed by an empty full <p></p>, div (without attributes) or br.
                    $result = preg_split('~(?<cut></p>|</div>|<br\s*/?>|<br [^>]*/?>)~u', $text, 2, PREG_SPLIT_DELIM_CAPTURE);
                    if ($result && count($result) === 3) {
                        $output = $result[0] . $result[1];
                        $more = $result[2];
                    }
                } else {
                    $pos = mb_strpos($text, "\n");
                    if ($pos) {
                        $output = mb_substr($text, 0, $pos + 1) . '…';
                        $more = mb_substr($text, $pos + 1);
                    }
                }
                break;

            case 'double_new_line':
                if (mb_substr($text, 0, 1) === '<') {
                    // A end p, div or br followed by an empty full <p></p>, div (without attributes) or br.
                    $result = preg_split('~(?<cut><(?:/(?:p|div)|br(?:\s*| [^>]*)/?)>\s*<(?:(?:p|div)>\s*</(?:p|div)|br(?:\s*| [^>]*)/?)>)~u', $text, 2, PREG_SPLIT_DELIM_CAPTURE);
                    if ($result && count($result) === 3) {
                        $output = $result[0] . $result[1];
                        $more = $result[2];
                    }
                } else {
                    // Normally cleaned on save.
                    $output = str_replace(["\n\r", "\r\n", "\r"], ["\n", "\n", "\n"], $text);
                    $pos = mb_strpos($output, "\n\n");
                    if ($pos) {
                        $more = mb_substr($output, $pos + 1);
                        $output = mb_substr($output, 0, $pos + 1) . '…';
                    }
                }
                break;

            case 'second_or_nth':
            case 'second_or_new_line':
            case 'second_or_double_new_line':
                if (!$resource) {
                    return $this->startAndMore($text, null, null, $maxWords, substr($mode, 10));
                }
                // No break.
            case 'second':
                // Check if there is a second value in the specified properties.
                $template = $resource->resourceTemplate();
                $term = $template && $template->descriptionProperty()
                    ? $template->descriptionProperty()->term()
                    : 'dcterms:description';
                foreach ($properties as $property) {
                    $property ??= $term;
                    $values = $resource->value($property, ['all' => true, 'lang' => $lang ? [$lang, ''] : null]);
                    if (empty($values[1])) {
                        continue;
                    }
                    $more = (string) $values[1]->asHtml();
                    if (strlen($more)) {
                        break;
                    }
                }
                if (!strlen((string) $more) && $mode !== 'second') {
                    return $this->startAndMore($text, null, null, $maxWords, substr($mode, 10));
                }
                break;

            // Other cases are properties.
            default:
                $more = $resource->value($mode, ['lang' => $lang ? [$lang, ''] : null]);
                if ($more) {
                    $more = (string) $more->asHtml();
                }
                if ($more === $text) {
                    $more = null;
                }
                break;
        }

        if ($output) {
            $output = trim($output);
            if (mb_substr($output, 0, 1) !== '<') {
                $output = nl2br($output);
            }
        }

        if ($more) {
            $more = trim($more);
            if (mb_substr($more, 0, 1) !== '<') {
                $more = nl2br($more);
            }
        }

        return [
            $output,
            $more,
        ];
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
     * @todo Use a module or resource page block.
     *
     * @return array List of medias for the main viewer, and list of links.
     *
     * @deprecated Use module BlockPlus.
     */
    public function mainViewerAndLinks(ItemRepresentation $item, bool $hasLightGallery = false): array
    {
        $plugins = $this->view->getHelperPluginManager();
        $siteSetting = $plugins->get('siteSetting');

        $viewing = $this->listMediasByViewer($item);

        $viewing['viewer'] = null;

        // This option is deprecated in Omeka S v4.
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
            if ($viewing['iiif_3x']) {
                $viewing['viewer'] = 'universalViewer';
                $viewing['is_iiif'] = true;
                $viewing['links'] = array_diff_key($viewing['all'], $viewing['iiif_3x']);
                return $viewing;
            }
        } elseif ($iiifViewer === 'mirador') {
            if ($viewing['iiif_3']) {
                $viewing['viewer'] = 'mirador';
                $viewing['is_iiif'] = true;
                $viewing['links'] = array_diff_key($viewing['all'], $viewing['iiif_3']);
                return $viewing;
            }
        } elseif ($iiifViewer === 'diva') {
            if ($viewing['iiif_2']) {
                $viewing['viewer'] = 'diva';
                $viewing['is_iiif'] = true;
                $viewing['links'] = array_diff_key($viewing['all'], $viewing['iiif_2']);
                return $viewing;
            }
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
     *
     * @deprecated Use module BlockPlus.
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

            // Other files.
            'other_files' => [],

            // Images directly viewable by a browser.
            'image_web' => [],

            // Diva accepts only images currently.
            'iiif_2' => [],
            // Mirador accept image, audio and video.
            'iiif_3' => [],
            // Universal viewer accept extended data (pdf, models).
            'iiif_3x' => [],

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
                    $viewing['iiif_2'][$mediaId] = $media;
                    $viewing['iiif_3'][$mediaId] = $media;
                    $viewing['iiif_3x'][$mediaId] = $media;
                    $viewing['lightGallery'][$mediaId] = $media;
                } elseif ($mediaTypeBase === 'audio') {
                    $viewing['audio'][$mediaId] = $media;
                    $viewing['iiif_3'][$mediaId] = $media;
                    $viewing['iiif_3x'][$mediaId] = $media;
                    $viewing['lightGallery'][$mediaId] = $media;
                } elseif ($mediaTypeBase === 'video') {
                    $viewing['video'][$mediaId] = $media;
                    $viewing['iiif_3'][$mediaId] = $media;
                    $viewing['iiif_3x'][$mediaId] = $media;
                    $viewing['lightGallery'][$mediaId] = $media;
                } elseif ($mediaTypeBase === 'model') {
                    $viewing['model'][$mediaId] = $media;
                    $viewing['iiif_3x'][$mediaId] = $media;
                    $viewing['lightGalleryIframe'][$mediaId] = $media;
                } elseif ($mediaType === 'application/pdf') {
                    $viewing['pdf'][$mediaId] = $media;
                    $viewing['iiif_3x'][$mediaId] = $media;
                    $viewing['lightGallery'][$mediaId] = $media;
                    $viewing['lightGalleryIframe'][$mediaId] = $media;
                } else {
                    $viewing['other_files'][$mediaId] = $media;
                }
            } elseif ($mediaRenderer === 'iiif' || $mediaRenderer === 'tile') {
                // TODO Not sure what is the iiif content.
                $viewing['iiif_2'][$mediaId] = $media;
                $viewing['iiif_3'][$mediaId] = $media;
                $viewing['iiif_3x'][$mediaId] = $media;
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
     *
     * @deprecated Integrated natively in Omeka S.
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
                case 'social_facebook':
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

                case 'social_pinterest':
                case 'pinterest':
                    $attrs = [
                        'id' => 'button-pinterest',
                        'href' => 'https://pinterest.com/pin/create/link/?url=' . $encodedUrl . '&description=' . $encodedTitle,
                        'title' => $translate('Share on Pinterest'), // @translate
                        'onclick' => $onclick,
                        'target' => '_blank',
                        'class' => 'share-page icon-pinterest',
                        'tabindex' => '0',
                    ];
                    break;

                case 'social_twitter':
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

                case 'social_email':
                case 'email':
                    $attrs = [
                        'href' => 'mailto:?subject=' . $encodedTitle . '&body=' . rawurlencode(sprintf($translate("%s%s\n-\n%s"), $siteTitle, $title === $siteTitle ? '' : "\n-\n" . $title, $url)),
                        'title' => $translate('Partager par mail'),
                        'class' => 'share-item icon-mail',
                        'tabindex' => '0',
                    ];
                    break;
                default:
                    continue 2;
            }
            $result[$social] = $attrs;
        }
        return $result;
    }

    /**
     * @todo Keys are not checked, but this is only use internaly.
     * @see \Laminas\View\Helper\HtmlAttributes
     */
    public function arrayToAttributes(array $attributes): string
    {
        $escapeAttr = $this->view->plugin('escapeHtmlAttr');
        return implode(' ', array_map(function ($key, $value) use ($escapeAttr) {
            if (is_bool($value)) {
                return $value ? $key . '="' . $key . '"' : '';
            }
            return $key . '="' . $escapeAttr($value) . '"';
        }, array_keys($attributes), $attributes));
    }

    /**
     * Get a theme setting of any site.
     *
     * Core helpers $themeSetting() return current site args.
     *
     * Warning: The site theme should be the same than the current one.
     */
    public function themeSetting($id, $default = null, $siteId = null)
    {
        if (!$siteId) {
            return $this->view->themeSetting($id, $default);
        }
        $currentTheme = $this->getServiceLocator()->get('Omeka\Site\ThemeManager')->getCurrentTheme();
        $themeSettings = $this->view->siteSetting($currentTheme->getSettingsKey(), false, $siteId);
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
ORDER BY site.id ASC
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

    public function hasMappingOrMarkers(?int $siteId = null): bool
    {
        static $hasMappingInAllSites;
        static $isOldVersion;
        static $results = [];

        if (is_null($hasMappingInAllSites)) {
            $hasMappingInAllSites = $this->isModuleActive('Mapping');
            if (!$hasMappingInAllSites) {
                return false;
            }
            $api = $this->view->plugin('api');
            try {
                $mapping = $api->search('mappings')->getTotalResults();
            } catch (\Exception $e) {
                return false;
            }
            $isOldVersion = !$this->isModuleActive('Mapping', '2.0');
            $markers = $isOldVersion
                ? $api->search('mapping_markers')->getTotalResults()
                : $api->search('mapping_features')->getTotalResults();
            $hasMappingInAllSites = $results[$siteId] = ($mapping + $markers) > 0;
        }

        if (!$hasMappingInAllSites || empty($siteId)) {
            return $hasMappingInAllSites;
        }

        if (!isset($results[$siteId])) {
            $mapping = $api->search('mappings', ['site_id' => $siteId])->getTotalResults();
            $markers = $isOldVersion
                ? $api->search('mapping_markers', ['site_id' => $siteId])->getTotalResults()
                : $api->search('mapping_features', ['site_id' => $siteId])->getTotalResults();
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
}
