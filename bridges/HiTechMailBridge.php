<?php
class HiTechMailBridge extends BridgeAbstract {
    const NAME = 'Hi-Tech.Mail.ru';
    const URI = 'https://hi-tech.mail.ru';
    const DESCRIPTION = 'Новости высоких технологий с Hi-Tech.Mail.ru';
    const MAINTAINER = 'FatNik';
    const CACHE_TIMEOUT = 3600;

    const PARAMETERS = [
        [
            'limit' => [
                'name' => 'Лимит новостей',
                'type' => 'number',
                'defaultValue' => 10,
                'title' => 'Количество новостей для загрузки (максимум 20)'
            ],
            'fulltext' => [
                'name' => 'Полный текст',
                'type' => 'checkbox',
                'title' => 'Загружать полный текст статей'
            ]
        ]
    ];

    const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Safari/605.1.15'
    ];

    private function getHtmlWithUserAgent($url) {
        $userAgent = self::USER_AGENTS[array_rand(self::USER_AGENTS)];
        $headers = [
            'User-Agent: ' . $userAgent,
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer: https://hi-tech.mail.ru/',
            'DNT: 1'
        ];

        sleep(rand(1, 3));
        $html = getSimpleHTMLDOM($url, $headers);
        if (!$html) {
            throw new Exception("Не удалось загрузить страницу: $url");
        }
        return $html;
    }

    private function extractNewsData($html) {
        // Пытаемся получить данные из JSON
        $script = $html->find('script#ht-script', 0);
        if ($script) {
            $json = str_replace('window.__PRELOADED_STATE__ = ', '', $script->innertext);
            $json = trim($json, '; ');
            $data = json_decode($json, true);

            if (isset($data['data']['page']['items'])) {
                return $data['data']['page']['items'];
            }
        }

        // Если JSON не найден, парсим HTML
        $news = [];
        foreach ($html->find('div[data-qa="ArticleTeaser"]') as $item) {
            $titleElem = $item->find('h3[data-qa="Title"] a', 0);
            if (!$titleElem) continue;

            $news[] = [
                'title' => $titleElem->plaintext,
                'href' => $titleElem->href,
                'description' => $item->find('div[data-qa="Text"]', 0)->plaintext ?? '',
                'published' => [
                    'rfc3339' => $item->find('time', 0)->datetime ?? ''
                ],
                'authors' => [
                    ['name' => $item->find('span[data-qa="Text"] a', 0)->plaintext ?? '']
                ],
                'picture' => $this->extractImageData($item)
            ];
        }

        return $news;
    }

    private function extractImageData($element) {
        $img = $element->find('img', 0);
        if (!$img) return null;

        $src = $img->src ?? $img->getAttribute('data-src');
        if (!$src) return null;

        // Пытаемся извлечь UUID из URL
        if (preg_match('/\/p\/([a-f0-9-]+)\//', $src, $matches)) {
            return [
                'uuid' => $matches[1],
                'key' => pathinfo(parse_url($src, PHP_URL_PATH), PATHINFO_FILENAME),
                'fmt' => ['jpg'],
                'baseURL' => 'https://resizer.mail.ru/p/'
            ];
        }

        return null;
    }

    private function getFullArticleText($url) {
        try {
            $html = $this->getHtmlWithUserAgent($url);
            $content = $html->find('article.article', 0);
            if (!$content) return 'Полный текст не найден';

            foreach ($content->find('div.ad, script, style, iframe') as $elem) {
                $elem->remove();
            }

            foreach ($content->find('img') as $img) {
                $src = $img->src ?? $img->getAttribute('data-src');
                if ($src && strpos($src, 'http') !== 0) {
                    $src = 'https:' . $src;
                }
                if ($src) {
                    $img->setAttribute('src', $src);
                    $img->setAttribute('style', 'max-width:100%; height:auto');
                }
            }

            return $content->innertext;
        } catch (Exception $e) {
            return 'Не удалось загрузить полный текст: ' . $e->getMessage();
        }
    }

    public function collectData() {
        $limit = min($this->getInput('limit') ?? 10, 20);
        $loadFullText = $this->getInput('fulltext');

        try {
            $html = $this->getHtmlWithUserAgent('https://hi-tech.mail.ru/news/');
            $newsItems = $this->extractNewsData($html);

            if (empty($newsItems)) {
                throw new Exception("Не удалось извлечь данные новостей");
            }

            $count = 0;
            foreach ($newsItems as $item) {
                if ($count >= $limit) break;

                $title = $item['title'] ?? 'Без названия';
                $link = $item['href'];
                if (strpos($link, 'http') !== 0) {
                    $link = self::URI . $link;
                }

                $description = $item['description'] ?? '';
                $author = $item['authors'][0]['name'] ?? '';

                // Дата
                $date = DateTime::createFromFormat(DateTime::ATOM, $item['published']['rfc3339'] ?? '');
                $timestamp = $date ? $date->getTimestamp() : time();

                // Изображение
                $image_url = null;
                if (isset($item['picture'])) {
                    $pic = $item['picture'];
                    $image_url = $pic['baseURL'] . $pic['uuid'] . '/' . $pic['key'] . '.' . $pic['fmt'][0];
                }

                // Формируем контент
                $content = '';
                if ($image_url) {
                    $content .= "<p><img src='{$image_url}' alt='{$title}' style='max-width:100%; height:auto'></p>";
                }

                if ($author) {
                    $content .= "<p><em>Автор: {$author}</em></p>";
                }

                $content .= "<p>{$description}</p>";

                if ($loadFullText) {
                    $fullText = $this->getFullArticleText($link);
                    $content .= $fullText;
                }

                $this->items[] = [
                    'title' => $title,
                    'uri' => $link,
                    'timestamp' => $timestamp,
                    'content' => $content,
                    'author' => $author,
                    'enclosures' => $image_url ? [$image_url] : []
                ];

                $count++;
            }

        } catch (Exception $e) {
            $this->items[] = [
                'title' => 'Ошибка загрузки Hi-Tech.Mail.ru',
                'uri' => self::URI,
                'timestamp' => time(),
                'content' => 'Произошла ошибка: ' . $e->getMessage()
            ];
        }
    }
}
