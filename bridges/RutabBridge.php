<?php
class RutabBridge extends BridgeAbstract {
    const NAME = 'Rutab';
    const URI = 'https://rutab.net';
    const DESCRIPTION = 'Новости Rutab.net по категориям.';
    const MAINTAINER = 'FatNik';

    // Список разделов и их URL
    const PARAMETERS = [
        [
            'section' => [
                'name' => 'Раздел',
                'type' => 'list',
                'values' => [
                    'Технологии' => 'hardware',
                    'Техника' => 'tehnika',
                    'Игры' => 'video-games',
                    'Кино' => 'movies',
                    'Бытовая-техника' => 'bytovaya-tehnika',
                    'Наука' => 'novosti-nauka'
                ]
            ],
            'fulltext' => [
                'name' => 'Загружать полный текст',
                'type' => 'checkbox',
                'title' => 'Отметьте, чтобы загружать полный текст статей'
            ],
            'limit' => [
                'name' => 'Количество новостей',
                'type' => 'number',
                'defaultValue' => 10,
                'title' => 'Укажите количество новостей для загрузки (максимум 20)',
                'required' => false
            ]
        ]
    ];

    // Список User-Agent для ротации
    const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0'
    ];

    /**
     * Получаем HTML-контент страницы с обработкой ошибок
     * @param string $url URL для загрузки
     * @return simple_html_dom Объект DOM
     * @throws Exception Если не удалось загрузить страницу
     */
    private function getHtmlWithUserAgent($url) {
        // Выбираем случайный User-Agent
        $userAgent = self::USER_AGENTS[array_rand(self::USER_AGENTS)];

        // Устанавливаем заголовки
        $header = [
            'User-Agent: ' . $userAgent,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5'
        ];

        try {
            $html = getSimpleHTMLDOM($url, $header);
            if (!$html) {
                throw new Exception("Не удалось загрузить страницу: $url");
            }
            return $html;
        } catch (Exception $e) {
            throw new Exception("Ошибка при загрузке страницы: " . $e->getMessage());
        }
    }

    /**
     * Получаем полный текст статьи
     * @param string $url URL статьи
     * @return string Полный текст статьи
     */
    private function getFullArticleText($url) {
        try {
            $articleHtml = $this->getHtmlWithUserAgent($url);
            $content = $articleHtml->find('div.topic-content', 0);
            return $content ? $content->innertext : 'Полный текст не найден';
        } catch (Exception $e) {
            return 'Не удалось загрузить полный текст: ' . $e->getMessage();
        }
    }

    public function collectData() {
        // Получаем параметры из настроек моста
        $section = $this->getInput('section');
        $loadFullText = $this->getInput('fulltext');
        $limit = $this->getInput('limit') ?: 10;

        // Ограничиваем максимальное количество новостей
        $limit = min($limit, 20);

        // Урлы для специальных разделов
        $special_sections = [
            'tehnika' => 'https://rutab.net/b/union/tehnika/'
        ];

        // Выбираем URL для запроса
        $url = $special_sections[$section] ?? "https://rutab.net/b/r/{$section}/";

        try {
            // Загружаем страницу с User-Agent
            $html = $this->getHtmlWithUserAgent($url);

            // Счетчик для ограничения количества новостей
            $count = 0;

    foreach ($html->find('article.topic') as $article) {
        if ($count >= $limit) break;

        // Парсим основные данные статьи
        $title = $article->find('h3.topic-title a, h3.title-undex a', 0)->plaintext;
        $link = $article->find('h3.topic-title a, h3.title-undex a', 0)->href;
        $date = strtotime($article->find('time', 0)->datetime);
        $content = $article->find('span.cstcut', 0)->innertext;

        // Улучшенный парсинг картинок
        $image = $article->find('img.topic_preview, img.lazyload, [data-src]', 0);
        $image_url = null;

        if ($image) {
            // Проверяем разные возможные атрибуты с URL
            $image_url = $image->getAttribute('data-src') 
                       ?: $image->getAttribute('src') 
                       ?: $image->getAttribute('data-original');

            // Если URL относительный, добавляем домен
            if ($image_url && strpos($image_url, 'http') !== 0) {
                $image_url = self::URI . ltrim($image_url, '/');
            }
        }

        // Если включена опция полного текста
        if ($loadFullText) {
            $content = $this->getFullArticleText($link);
        }

        // Формируем контент с учетом изображения
        $full_content = '';
        if ($image_url) {
            // Добавляем ленивую загрузку для совместимости
            $full_content .= "<img src='{$image_url}' data-original='{$image_url}' style='max-width:100%'/><br>";
        }
        $full_content .= $content;

        $this->items[] = [
            'title' => $title,
            'uri' => $link,
            'timestamp' => $date,
            'content' => $full_content,
            'enclosures' => $image_url ? [$image_url] : [] // Добавляем в enclosures для RSS
        ];

        $count++;
    }

        } catch (Exception $e) {
            // В случае ошибки добавляем сообщение в ленту
            $this->items[] = [
                'title' => 'Ошибка загрузки',
                'uri' => self::URI,
                'timestamp' => time(),
                'content' => 'Произошла ошибка при загрузке новостей: ' . $e->getMessage()
            ];
        }
    }
}
