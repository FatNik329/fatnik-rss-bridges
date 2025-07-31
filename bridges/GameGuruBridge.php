<?php
class GameGuruBridge extends BridgeAbstract {
    const NAME = 'GameGuru.ru';
    const URI = 'https://gameguru.ru';
    const DESCRIPTION = 'Новости игр с фильтрацией по рубрикам.';
    const MAINTAINER = 'FatNik';

    // Список популярных User-Agent для ротации
    const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0'
    ];

    // Параметры моста с новыми опциями
    const PARAMETERS = [
        [
            'rubrics' => [
                'name' => 'Рубрики',
                'type' => 'text',
                'exampleValue' => 'news,longread,review',
                'title' => 'Список рубрик через запятую (news,longread,review,top,interview,reportage)'
            ],
            'limit' => [
                'name' => 'Лимит новостей',
                'type' => 'number',
                'defaultValue' => 15,
                'title' => 'Количество новостей для загрузки (максимум 30)'
            ],
            'fulltext' => [
                'name' => 'Загружать полный текст',
                'type' => 'checkbox',
                'title' => 'Отметьте, чтобы загружать полный текст статей'
            ]
        ]
    ];

    // Названия рубрик для отображения
    private $rubricNames = [
        'news' => 'Новости',
        'longread' => 'Спецы и мнения',
        'review' => 'Обзоры и превью',
        'top' => 'Топы и дайджесты',
        'interview' => 'Интервью',
        'reportage' => 'Репортажи'
    ];

    /**
     * Загружает HTML-страницу с использованием случайного User-Agent
     * @param string $url URL для загрузки
     * @return simple_html_dom Объект DOM
     * @throws Exception Если загрузка не удалась
     */
    private function getHtmlWithUserAgent($url) {
        $userAgent = self::USER_AGENTS[array_rand(self::USER_AGENTS)];
        $header = [
            'User-Agent: ' . $userAgent,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8'
        ];

        $html = getSimpleHTMLDOM($url, $header);
        if (!$html) {
            throw new Exception("Не удалось загрузить страницу: $url");
        }
        return $html;
    }

    /**
     * Получает полный текст статьи
     * @param string $url URL статьи
     * @return string Полный текст статьи
     */
    private function getFullArticleText($url) {
        try {
            $articleHtml = $this->getHtmlWithUserAgent($url);
            $content = $articleHtml->find('div.article-content', 0); // Измените селектор при необходимости
            return $content ? $content->innertext : 'Полный текст не найден';
        } catch (Exception $e) {
            return 'Не удалось загрузить полный текст: ' . $e->getMessage();
        }
    }

    public function collectData() {
        // Получаем параметры
        $rubrics = $this->getInput('rubrics');
        $limit = min($this->getInput('limit') ?? 15, 30); // Ограничиваем максимум 30 новостей
        $loadFullText = $this->getInput('fulltext');

        // Формируем URL с учетом выбранных рубрик
        $url = 'https://gameguru.ru/articles/';
        if (!empty($rubrics)) {
            $rubrics = array_map('trim', explode(',', $rubrics));
            $validRubrics = array_intersect($rubrics, array_keys($this->rubricNames));
            if (!empty($validRubrics)) {
                $url .= '?rubrics=' . implode('%2C', $validRubrics);
            }
        }

        try {
            $html = $this->getHtmlWithUserAgent($url);
            $count = 0;

            foreach ($html->find('div.short-news') as $element) {
                if ($count >= $limit) break;

                // Парсим основные данные
                $titleElem = $element->find('div.short-news-title a', 0);
                if (!$titleElem) continue;

                $title = $titleElem->plaintext;
                $link = self::URI . $titleElem->href;

                // Обработка даты - исправленная версия
                $dateElem = $element->find('div.short-news-date', 0);
                if ($dateElem) {
                    $dateText = trim($dateElem->plaintext);
                    $date = DateTime::createFromFormat('d.m.Y', $dateText);
                    $timestamp = $date ? $date->getTimestamp() : time();
                } else {
                    $timestamp = time();
                }

                // Получаем изображение
                $image = $element->find('picture img, picture source', 0);
                $image_url = $image ? self::URI . ($image->src ?? $image->srcset) : null;

                // Получаем рубрику
                $rubric = '';
                $rubricElem = $element->find('div.short-news-rubric', 0);
                if ($rubricElem) {
                    $rubric = trim($rubricElem->plaintext);
                }

                // Формируем контент
                $content = '';
                if ($image_url) {
                    $content .= "<img src='{$image_url}' alt='{$title}'><br>";
                }
                if ($rubric) {
                    $content .= "<strong>{$rubric}</strong><br>";
                }

                // Если включена опция полного текста, загружаем его
                if ($loadFullText) {
                    $articleContent = $this->getFullArticleText($link);
                    $content .= $articleContent;
                } else {
                    $content .= "<a href='{$link}'>{$title}</a>";
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
            // Добавляем сообщение об ошибке в ленту
            $this->items[] = [
                'title' => 'Ошибка загрузки GameGuru.ru',
                'uri' => self::URI,
                'timestamp' => time(),
                'content' => 'Произошла ошибка: ' . $e->getMessage()
            ];
        }
    }

    public function getName() {
        if (!empty($this->getInput('rubrics'))) {
            $rubrics = array_map('trim', explode(',', $this->getInput('rubrics')));
            $names = [];
            foreach ($rubrics as $rubric) {
                if (isset($this->rubricNames[$rubric])) {
                    $names[] = $this->rubricNames[$rubric];
                }
            }
            if (!empty($names)) {
                return self::NAME . ' | ' . implode(', ', $names);
            }
        }
        return parent::getName();
    }
}
