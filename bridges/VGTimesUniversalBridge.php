<?php
class VGTimesUniversalBridge extends BridgeAbstract {
    const NAME = 'VGTimes.ru';
    const URI = 'https://vgtimes.ru';
    const DESCRIPTION = 'Универсальный мост для VGTimes.ru с поддержкой основных разделов.';
    const CACHE_TIMEOUT = 3600;
    const MAINTAINER = "FatNik";

    // Параметры моста
    const PARAMETERS = [
        [
            'section' => [
                'name' => 'Раздел',
                'type' => 'list',
                'values' => [
                    'Новости' => 'news',
                    'Тесты' => 'tests',
                    'Статьи' => 'articles',
                    'Халява' => 'free'
                ],
                'defaultValue' => 'news'
            ],
            'limit' => [
                'name' => 'Лимит',
                'type' => 'number',
                'defaultValue' => 7,
                'title' => 'Максимум 15 для stealth-режима'
            ],
            'fulltext' => [
                'name' => 'Полный текст',
                'type' => 'checkbox',
                'title' => 'Может увеличить нагрузку'
            ]
        ]
    ];

    // User-Agents и другие методы остаются как в предыдущем рабочем варианте
    const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Safari/605.1.15',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:54.0) Gecko/20100101 Firefox/54.0'
    ];

    private function getHumanLikeHeaders() {
        return [
            'User-Agent: ' . self::USER_AGENTS[array_rand(self::USER_AGENTS)],
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer: ' . self::URI,
            'DNT: 1',
            'Connection: keep-alive'
        ];
    }

    private function humanDelay() {
        $delay = rand(1, 3);
        sleep($delay);
        if (rand(1, 10) == 1) sleep(rand(5, 8));
    }

    private function getHtmlWithUserAgent($url) {
        $this->humanDelay();
        $html = getSimpleHTMLDOM($url, $this->getHumanLikeHeaders());
        if (!$html) throw new Exception("Не удалось загрузить страницу: $url");
        return $html;
    }

    private function getFullArticleText($url) {
        try {
            $this->humanDelay();
            $articleHtml = $this->getHtmlWithUserAgent($url);
            $content = $articleHtml->find('div.article-content', 0);
            return $content ? $content->innertext : 'Полный текст не найден';
        } catch (Exception $e) {
            return 'Не удалось загрузить полный текст: ' . $e->getMessage();
        }
    }

    private function parseDate($dateText) {
        if (preg_match('/Сегодня, (\d{1,2}:\d{2})/', $dateText, $matches)) {
            $today = new DateTime();
            $time = DateTime::createFromFormat('H:i', $matches[1]);
            $today->setTime($time->format('H'), $time->format('i'));
            return $today->getTimestamp();
        } elseif (preg_match('/Вчера, (\d{1,2}:\d{2})/', $dateText, $matches)) {
            $yesterday = new DateTime('yesterday');
            $time = DateTime::createFromFormat('H:i', $matches[1]);
            $yesterday->setTime($time->format('H'), $time->format('i'));
            return $yesterday->getTimestamp();
        } elseif (preg_match('/(\d{1,2}) (\w+) (\d{4}), (\d{1,2}:\d{2})/', $dateText, $matches)) {
            $months = [
                'января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4,
                'мая' => 5, 'июня' => 6, 'июля' => 7, 'августа' => 8,
                'сентября' => 9, 'октября' => 10, 'ноября' => 11, 'декабря' => 12
            ];
            $date = DateTime::createFromFormat('j.n.Y H:i',
                $matches[1] . '.' . $months[$matches[2]] . '.' . $matches[3] . ' ' . $matches[4]);
            return $date ? $date->getTimestamp() : time();
        }
        return time();
    }

public function collectData() {
    $section = $this->getInput('section');
    $limit = min($this->getInput('limit') ?? 10, 15);
    $loadFullText = $this->getInput('fulltext');

    $urls = [
        'news' => 'https://vgtimes.ru/news/',
        'tests' => 'https://vgtimes.ru/tests/',
        'articles' => 'https://vgtimes.ru/articles/',
        'free' => 'https://vgtimes.ru/free/'
    ];

    try {
        $html = $this->getHtmlWithUserAgent($urls[$section]);
        $count = 0;

        foreach ($html->find('ul.list-items > li') as $element) {
            if ($count >= $limit) break;

            // Заголовок и ссылка
            $titleElem = $element->find('div.item-name a[data-pjax=showfull], div.item-name a[data-pjax=tests]', 0);
            if (!$titleElem) continue;

            $title = $titleElem->plaintext;
            $link = strpos($titleElem->href, 'http') === 0 ? $titleElem->href : self::URI . $titleElem->href;

            // Дата
            $dateElem = $element->find('span.news_item_time', 0);
            $timestamp = $dateElem ? $this->parseDate(trim($dateElem->plaintext)) : time();

            // Изображение (обновленный парсинг)
            $image = $element->find('img.lazyload', 0);
            $image_url = null;

            if ($image) {
                $image_url = $image->getAttribute('data-src')
                           ?: $image->getAttribute('src');

                // Обработка CDN и относительных путей
                if ($image_url) {
                    if (strpos($image_url, '//') === 0) {
                        $image_url = 'https:' . $image_url;
                    } elseif (strpos($image_url, 'http') !== 0) {
                        $image_url = self::URI . ltrim($image_url, '/');
                    }

                    // Замена CDN-домена на основной при необходимости
                    $image_url = str_replace(
                        ['vgtimes.b-cdn.net', 'cdn.vgtimes.ru'],
                        'vgtimes.ru',
                        $image_url
                    );
                }
            }

            // Описание
            $description = $element->find('div.item-text div', 0)->innertext;

            // Формирование контента
            $content = '';
            if ($image_url) {
                $content .= "<img src='" . htmlspecialchars($image_url) . "' alt='" . htmlspecialchars($title) . "'><br>";
            }
            $content .= $loadFullText ? $this->getFullArticleText($link) : $description;

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
        error_log('VGTimes Bridge Error: ' . $e->getMessage());
        $this->items[] = [
            'title' => 'Ошибка загрузки ' . self::NAME,
            'uri' => self::URI,
            'timestamp' => time(),
            'content' => 'Произошла ошибка: ' . $e->getMessage()
        ];
    }
}

    public function getName() {
        if ($this->getInput('section')) {
            $sectionNames = [
                'news' => 'Новости',
                'tests' => 'Тесты',
                'articles' => 'Статьи',
                'free' => 'Халява'
            ];
            return self::NAME . ' | ' . $sectionNames[$this->getInput('section')];
        }
        return parent::getName();
    }
}
