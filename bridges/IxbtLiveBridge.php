<?php
class IxbtLiveBridge extends BridgeAbstract {
    const NAME = 'iXBT Live';
    const URI = 'https://www.ixbt.com/live/';
    const DESCRIPTION = 'Последние новости с iXBT Live';
    const MAINTAINER = 'FatNik';

    const PARAMETERS = [
        [
            'limit' => [
                'name' => 'Лимит новостей',
                'type' => 'number',
                'defaultValue' => 6
            ]
        ]
    ];

    public function collectData() {
        $limit = $this->getInput('limit') ?? 20;
        $html = getSimpleHTMLDOM(self::URI);

        $count = 0;
        foreach ($html->find('article.topic-thumbnail') as $element) {
            if ($count >= $limit) break;

            // Пропускаем блок с мини-новостями
            if ($element->hasClass('topic-thumbnail-special')) continue;

            $titleElem = $element->find('h3.topic-title a', 0);
            if (!$titleElem) continue;

            $title = $titleElem->plaintext;
            $link = $titleElem->href;

            // Обработка даты
            $dateElem = $element->find('time', 0);
            if ($dateElem) {
                $dateText = trim($dateElem->plaintext);
                $datetime = $dateElem->datetime;
                $timestamp = strtotime($datetime);
            } else {
                $timestamp = time();
            }

            // Получаем изображение
            $image = $element->find('img[loading=lazy]', 0);
            $image_url = $image ? $image->src : null;

            // Получаем описание
            $description = $element->find('div.topic-content', 0)->plaintext ?? '';

            // Получаем категорию
            $category = $element->find('li.topic-info-type', 0)->plaintext ?? '';

            // Формируем контент
            $content = '';
            if ($image_url) {
                $content .= "<img src='{$image_url}' alt='{$title}'><br>";
            }
            if ($category) {
                $content .= "<small><strong>{$category}</strong></small><br>";
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
