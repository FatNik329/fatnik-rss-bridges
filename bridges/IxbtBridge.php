<?php
class IxbtBridge extends BridgeAbstract {
    const NAME = 'iXBT Комбинированный';
    const URI = 'https://www.ixbt.com/';
    const DESCRIPTION = 'Новости и обзоры с iXBT.com';
    const MAINTAINER = 'FatNik';
    const CACHE_TIMEOUT = 3600;

    const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.114 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:89.0) Gecko/20100101 Firefox/89.0',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 13_2_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.0.3 Mobile/15E148 Safari/604.1'
    ];

    const PARAMETERS = [
        'Раздел' => [
            'section' => [
                'name' => 'Раздел',
                'type' => 'list',
                'values' => [
                    'Обзоры' => 'articles',
                    'Новости' => 'news'
                ],
                'defaultValue' => 'articles'
            ],
            'limit' => [
                'name' => 'Лимит статей',
                'type' => 'number',
                'defaultValue' => 10,
                'required' => true,
                'title' => 'Максимум 10 статей'
            ]
        ]
    ];

    public function collectData() {
        $limit = min($this->getInput('limit'), 10);
        $section = $this->getInput('section');

        $url = $this->getSectionUrl($section);
        $html = $this->getHtmlWithRetry($url);

        $count = 0;
        $items = $this->findItems($html, $section);

        foreach ($items as $element) {
            if ($count >= $limit) break;

            try {
                $item = $this->processItem($element, $section);
                if ($item) {
                    $this->items[] = $item;
                    $count++;
                }
            } catch (Exception $e) {
                continue;
            }
        }
    }

    private function getSectionUrl($section) {
        $urls = [
            'articles' => self::URI . 'articles/current/',
            'news' => self::URI . 'news/?show=tape'
        ];
        return $urls[$section] ?? $urls['articles'];
    }

    private function getHtmlWithRetry($url, $retry = 3) {
        $header = ['User-Agent: ' . self::USER_AGENTS[array_rand(self::USER_AGENTS)]];

        for ($i = 0; $i < $retry; $i++) {
            try {
                $html = getSimpleHTMLDOM($url, $header);
                usleep(rand(500000, 2000000));
                return $html;
            } catch (Exception $e) {
                if ($i === $retry - 1) throw $e;
                sleep(rand(1, 3));
            }
        }
    }

    private function findItems($html, $section) {
        if ($section === 'articles') {
            return $html->find('div.item__full');
        } else { // news
            return $html->find('div.b-block.block__newslistbig > div.newslistbig__items > div.item');
        }
    }

    private function processItem($element, $section) {
        if ($section === 'articles') {
            return $this->processArticleItem($element);
        } else {
            return $this->processNewsItem($element);
        }
    }

    private function processArticleItem($element) {
        $titleElem = $element->find('a.item__text--title', 0);
        if (!$titleElem) return null;

        $title = trim($titleElem->plaintext);
        $link = $this->resolveUrl($titleElem->href);

        $dateElem = $element->find('div.info__date', 0);
        $dateText = $dateElem ? trim($dateElem->plaintext) : '';
        $timestamp = $this->parseDate($dateText);

        $fullContent = $this->fetchFullArticle($link);

        $images = $this->fetchImages($element, $fullContent['content'] ?? '');

        $authors = $this->fetchAuthors($element);
        $category = $element->find('div.info__category a', 0)->plaintext ?? '';

        $content = $this->buildContent($images, $category, $authors, $fullContent);

        return [
            'title' => $title,
            'uri' => $link,
            'timestamp' => $timestamp,
            'content' => $content,
            'author' => implode(', ', $authors),
            'enclosures' => $images,
            'categories' => [$category]
        ];
    }

    private function processNewsItem($element) {
        $titleElem = $element->find('h2.no-margin a', 0);
        if (!$titleElem) return null;

        $title = trim($titleElem->plaintext);
        $link = $this->resolveUrl($titleElem->href);

        // Подзаголовок (h4)
        $subtitle = $element->find('h4', 0)->plaintext ?? '';

        // Дата и время
        $dateElem = $element->find('span.time_iteration_icon', 0);
        $dateText = $dateElem ? trim($dateElem->plaintext) : '';
        $timestamp = $this->parseNewsDate($dateText);

        // Теги
        $tags = [];
        $tagsElem = $element->find('p.b-article__tags__list a');
        foreach ($tagsElem as $tag) {
            $tags[] = trim($tag->plaintext);
        }

        // Источник
        $source = $element->find('p.b-article__source__list a', 0)->plaintext ?? '';

        $fullContent = $this->fetchFullArticle($link);
        $images = $this->fetchImages($element, $fullContent['content'] ?? '');

        $content = $this->buildNewsContent($images, $tags, $source, $fullContent, $subtitle);

        return [
            'title' => $title,
            'uri' => $link,
            'timestamp' => $timestamp,
            'content' => $content,
            'categories' => $tags
        ];
    }

    private function parseNewsDate($dateText) {
        // Формат времени в новостях: "13:45"
        $today = date('Y-m-d');
        return strtotime("$today $dateText");
    }

    private function buildNewsContent($images, $tags, $source, $fullContent, $subtitle) {
        $content = '';

        if (!empty($subtitle)) {
            $content .= "<div class='news-subtitle'><strong>{$subtitle}</strong></div>";
        }

        if (!empty($images[0])) {
            $content .= "<figure><img src='{$images[0]}' alt='Основное изображение'></figure>";
        }

        $content .= "<div class='news-meta'>";
        if (!empty($tags)) {
            $content .= "<p><strong>Теги:</strong> " . implode(', ', $tags) . "</p>";
        }
        if (!empty($source)) {
            $content .= "<p><strong>Источник:</strong> {$source}</p>";
        }
        if (!empty($fullContent['jsonLd']['datePublished'])) {
            $date = date('d.m.Y H:i', strtotime($fullContent['jsonLd']['datePublished']));
            $content .= "<p><strong>Дата публикации:</strong> {$date}</p>";
        }
        $content .= "</div>";

        if (!empty($fullContent['jsonLd']['description'])) {
            $content .= "<blockquote>{$fullContent['jsonLd']['description']}</blockquote>";
        }

        $content .= $fullContent['content'] ?? '';

        return $content;
    }

    private function resolveUrl($relativeUrl) {
        if (strpos($relativeUrl, 'http') === 0) {
            return $relativeUrl;
        }
        return self::URI . ltrim($relativeUrl, '/');
    }

    private function parseDate($dateText) {
        if (empty($dateText)) return time();

        $formats = [
            'd.m.Y H:i',
            'Y-m-d H:i:s',
            'd F Y H:i'
        ];

        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $dateText);
            if ($date !== false) {
                return $date->getTimestamp();
            }
        }

        return strtotime($dateText);
    }

    private function fetchFullArticle($url) {
        try {
            $html = $this->getHtmlWithRetry($url);

            $jsonLd = $this->extractJsonLd($html);

            $content = $html->find('div.article__content, div.item__text', 0);
            if ($content) {
                foreach ($content->find('div.ad, script, iframe, div.social-likes, div.comment_parent') as $garbage) {
                    $garbage->outertext = '';
                }
                $cleanedContent = $content->innertext;
            } else {
                $cleanedContent = $jsonLd['articleBody'] ?? '';
            }

            return [
                'content' => $cleanedContent,
                'jsonLd' => $jsonLd
            ];
        } catch (Exception $e) {
            return ['content' => '', 'jsonLd' => []];
        }
    }

    private function extractJsonLd($html) {
        $jsonLd = [];
        $ldScript = $html->find('script[type="application/ld+json"]', 0);

        if ($ldScript) {
            try {
                $jsonLd = json_decode(html_entity_decode($ldScript->innertext), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $jsonLd = [];
                }
            } catch (Exception $e) {
            }
        }

        return is_array($jsonLd) ? $jsonLd : [];
    }

    private function fetchImages($element, $fullContent = '') {
        $images = [];

        // Основное изображение из элемента
        $mainImage = $element->find('img[src], img[data-src]', 0);
        if ($mainImage) {
            $src = $mainImage->getAttribute('data-src') ?: $mainImage->src;
            if ($src) {
                $images[] = $this->resolveUrl($src);
            }
        }

        // Изображения из контента
        if (!empty($fullContent)) {
            $contentHtml = str_get_html($fullContent);
            if ($contentHtml) {
                foreach ($contentHtml->find('img[src], img[data-src]') as $img) {
                    $src = $img->getAttribute('data-src') ?: $img->src;
                    if ($src && !in_array($src, $images)) {
                        $images[] = $this->resolveUrl($src);
                    }
                }
            }
        }

        return array_unique($images);
    }

    private function fetchAuthors($element) {
        $authors = [];

        foreach ($element->find('p.author') as $author) {
            $authorText = trim(str_replace([',', 'Автор:'], '', $author->plaintext));
            if (!empty($authorText)) {
                $authors[] = $authorText;
            }
        }

        return array_unique($authors);
    }

    private function buildContent($images, $category, $authors, $fullContent) {
        $content = '';

        if (!empty($images[0])) {
            $content .= "<figure><img src='{$images[0]}' alt='Основное изображение'></figure>";
        }

        $content .= "<div class='article-meta'>";
        if ($category) {
            $content .= "<p><strong>Категория:</strong> {$category}</p>";
        }
        if (!empty($authors)) {
            $content .= "<p><strong>Авторы:</strong> " . implode(', ', $authors) . "</p>";
        }
        if (!empty($fullContent['jsonLd']['datePublished'])) {
            $date = date('d.m.Y H:i', strtotime($fullContent['jsonLd']['datePublished']));
            $content .= "<p><strong>Дата публикации:</strong> {$date}</p>";
        }
        $content .= "</div>";

        if (!empty($fullContent['jsonLd']['description'])) {
            $content .= "<blockquote>{$fullContent['jsonLd']['description']}</blockquote>";
        }

        $content .= $fullContent['content'] ?? '';

        return $content;
    }
}
