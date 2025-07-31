<?php
class GismeteoNewsBridge extends BridgeAbstract {
    const NAME = 'Gismeteo Новости';
    const URI = 'https://www.gismeteo.ru/news/';
    const DESCRIPTION = 'Последние новости с Gismeteo';
    const MAINTAINER = 'FatNik';

    const PARAMETERS = [
        [
            'limit' => [
                'name' => 'Лимит новостей',
                'type' => 'number',
                'defaultValue' => 10
            ]
        ]
    ];

    public function collectData() {
        $limit = $this->getInput('limit') ?? 15;
        $html = getSimpleHTMLDOM(self::URI);

        $count = 0;
        foreach ($html->find('div.card-wrap') as $element) {
            if ($count >= $limit) break;

            $linkElem = $element->find('a.rss-card', 0);
            if (!$linkElem) continue;

            $title = $element->find('div.text-title', 0)->plaintext ?? 'Без названия';
            $link = 'https://www.gismeteo.ru' . $linkElem->href;

            // Обработка даты из атрибута data-pub-date
            $pubDate = $linkElem->getAttribute('data-pub-date');
            $timestamp = $pubDate ? strtotime($pubDate) : time();

            // Исправленный парсинг изображения
            $image = $element->find('div.js-lazy-image', 0);
            $image_url = $image ? str_replace('https:/', 'https://', $image->getAttribute('data-src')) : null;

            // Получаем описание
            $description = $element->find('div.text-excerpt', 0)->plaintext ?? '';

            // Получаем тег (категорию)
            $tag = $linkElem->getAttribute('data-tag') ?? '';

            // Формируем контент
            $content = '';
            if ($image_url) {
                $content .= "<img src='{$image_url}' alt='{$title}'><br>";
            }
            if ($tag) {
                $content .= "<small><strong>Категория:</strong> {$tag}</small><br>";
            }
            $content .= $description;

            $this->items[] = [
                'title' => $title,
                'uri' => $link,
                'timestamp' => $timestamp,
                'content' => $content,
                'enclosures' => $image_url ? [$image_url] : []
            ];

            $count++;
        }
    }
}
