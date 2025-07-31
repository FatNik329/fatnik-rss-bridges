<?php
class MySKUBridge extends BridgeAbstract {
    const NAME = 'MySKU.club';
    const URI = 'https://mysku.club';
    const DESCRIPTION = 'Обзоры, статьи и скидки с MySKU.club';
    const MAINTAINER = 'FatNik';
    const CACHE_TIMEOUT = 3600;

    const PARAMETERS = [
        [
            'section' => [
                'name' => 'Раздел',
                'type' => 'list',
                'values' => [
                    'Все' => 'all',
                    'Обзоры' => 'reviews',
                    'Заметки' => 'notes',
                    'Статьи' => 'topics',
                    'Скидки' => 'coupons'
                ],
                'defaultValue' => 'all'
            ],
            'limit' => [
                'name' => 'Лимит',
                'type' => 'number',
                'defaultValue' => 3,
                'title' => 'Максимум 5 для stealth-режима'
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
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Safari/605.1.15',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1'
    ];

    private function getHtmlWithUserAgent($url) {
        $userAgent = self::USER_AGENTS[array_rand(self::USER_AGENTS)];
        $headers = [
            'User-Agent: ' . $userAgent,
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer: ' . self::URI,
            'DNT: 1'
        ];

        // Случайная задержка 1-3 секунды
        sleep(rand(1, 3));

        $html = getSimpleHTMLDOM($url, $headers);
        if (!$html) {
            throw new Exception("Не удалось загрузить страницу: $url");
        }
        return $html;
    }

    private function getFullArticleText($url) {
        try {
            $html = $this->getHtmlWithUserAgent($url);
            $content = $html->find('div.topic-content-text', 0);
            return $content ? $content->innertext : 'Полный текст не найден';
        } catch (Exception $e) {
            return 'Не удалось загрузить полный текст: ' . $e->getMessage();
        }
    }

    private function parseDate($dateText) {
        $months = [
            'января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4,
            'мая' => 5, 'июня' => 6, 'июля' => 7, 'августа' => 8,
            'сентября' => 9, 'октября' => 10, 'ноября' => 11, 'декабря' => 12
        ];

        if (preg_match('/(\d{1,2}) (\w+) (\d{4}), (\d{1,2}:\d{2})/', $dateText, $matches)) {
            $date = DateTime::createFromFormat('j.n.Y H:i',
                $matches[1] . '.' . $months[$matches[2]] . '.' . $matches[3] . ' ' . $matches[4]);
            return $date ? $date->getTimestamp() : time();
        }
        return time();
    }

    public function collectData() {
        $section = $this->getInput('section');
        $limit = min($this->getInput('limit') ?? 10, 20);
        $loadFullText = $this->getInput('fulltext');

        $urls = [
            'all' => 'https://mysku.club/blog/',
            'reviews' => 'https://mysku.club/blog/reviews/',
            'notes' => 'https://mysku.club/blog/notes/',
            'topics' => 'https://mysku.club/blog/topics/',
            'coupons' => 'https://mysku.club/blog/coupons/'
        ];

        try {
            $html = $this->getHtmlWithUserAgent($urls[$section]);
            $count = 0;

            foreach ($html->find('div.topic-preview-page') as $topic) {
                if ($count >= $limit) break;

                // Заголовок
                $titleElem = $topic->find('div.topic-title a', 0);
                if (!$titleElem) continue;
                $title = trim($titleElem->plaintext);

                // Ссылка
                $link = $titleElem->href;
                if (strpos($link, 'http') !== 0) {
                    $link = self::URI . $link;
                }

                // Дата
                $dateElem = $topic->find('li.date span', 0);
                $timestamp = $dateElem ? $this->parseDate($dateElem->plaintext) : time();

                // Изображение
                $image = $topic->find('img.product-image', 0);
                $image_url = $image ? $image->src : null;
                if ($image_url && strpos($image_url, 'http') !== 0) {
                    $image_url = self::URI . $image_url;
                }

                // Описание
                $description = $topic->find('div.wrapper p', 0)->plaintext ?? '';

                // Купон (для раздела скидок)
                $coupon = '';
                if ($section === 'coupons') {
                    $couponElem = $topic->find('span.code-body-text', 0);
                    if ($couponElem) {
                        $coupon = "<p><strong>Код купона:</strong> " . $couponElem->plaintext . "</p>";
                    }
                }

                // Полный текст
                $content = '';
                if ($image_url) {
                    $content .= "<img src='{$image_url}' alt='{$title}' style='max-width:100%'><br>";
                }
                $content .= $coupon . $description;
                if ($loadFullText) {
                    $content = $this->getFullArticleText($link);
                }

                $this->items[] = [
                    'title' => $title,
                    'uri' => $link,
                    'timestamp' => $timestamp,
                    'content' => $content,
                    'enclosures' => $image_url ? [$image_url] : []
                ];

                $count++;
            }
        } catch (Exception $e) {
            $this->items[] = [
                'title' => 'Ошибка загрузки MySKU.club',
                'uri' => self::URI,
                'timestamp' => time(),
                'content' => 'Произошла ошибка: ' . $e->getMessage()
            ];
        }
    }

    public function getName() {
        if ($this->getInput('section')) {
            $sectionNames = [
                'all' => 'Все',
                'reviews' => 'Обзоры',
                'notes' => 'Заметки',
                'topics' => 'Статьи',
                'coupons' => 'Скидки'
            ];
            return self::NAME . ' | ' . $sectionNames[$this->getInput('section')];
        }
        return parent::getName();
    }
}
