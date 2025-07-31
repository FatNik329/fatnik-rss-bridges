<?php
class IxbtGamesBridge extends BridgeAbstract {
    const NAME = 'iXBT.Games';
    const URI = 'https://ixbt.games';
    const DESCRIPTION = 'Публикации iXBT.Games по категориям.';
    const MAINTAINER = 'FatNik';
    const CACHE_TIMEOUT = 3600;

    const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Safari/605.1.15'
    ];

    const PARAMETERS = [
        [
            'section' => [
                'name' => 'Раздел',
                'type' => 'list',
                'values' => [
                    'Новости' => 'news',
                    'Обзоры' => 'reviews',
                    'Итоги' => 'results',
                    'Инструментарий' => 'tools',
                    'Статьи' => 'articles'
                ],
                'defaultValue' => 'news'
            ],
            'limit' => [
                'name' => 'Количество статей',
                'type' => 'number',
                'defaultValue' => 7,
                'required' => false
            ],
            'full_content' => [ // Новый параметр для полного текста
                'name' => 'Полный текст',
                'type' => 'checkbox',
                'title' => 'Загружать полный текст статей (медленнее)'
            ]
        ]
    ];

    private function getRandomUserAgent() {
        return self::USER_AGENTS[array_rand(self::USER_AGENTS)];
    }

    private function getCustomHeaders() {
        return [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer: ' . self::URI . '/',
            'User-Agent: ' . $this->getRandomUserAgent()
        ];
    }

    // Новый метод для загрузки полного текста
    private function loadFullContent($url) {
        sleep(rand(1, 2)); // Задержка перед загрузкой
        $html = getSimpleHTMLDOM($url, $this->getCustomHeaders());
        if (!$html) return null;

        $content = $html->find('div.article-content', 0);
        return $content ? $content->innertext : null;
    }

    public function collectData() {
        $section = $this->getInput('section');
        $limit = $this->getInput('limit') ?? 5;
        $url = self::URI . '/' . $section . '/';

        sleep(rand(1, 3));
        $html = getSimpleHTMLDOM($url, $this->getCustomHeaders());

        if (!$html) {
            throw new \Exception('Сайт временно недоступен. Попробуйте позже.');
        }

        $articles = $html->find('div.card.card-widget.border-xs-none');
        if (empty($articles)) {
            throw new \Exception('Раздел временно пуст или изменилась структура сайта.');
        }

        $articles = array_slice($articles, 0, $limit);

        foreach ($articles as $article) {
            $item = [];
            $titleElement = $article->find('div.card-title a.card-link', 0);
            if (!$titleElement) continue;

            $item['title'] = trim($titleElement->plaintext);
            $item['uri'] = self::URI . $titleElement->href;
            $item['uid'] = md5($item['uri']);

            // Обработка даты
            if ($timeElement = $article->find('div.badge-time', 0)) {
                $timeText = trim(preg_replace('/<svg.*?<\/svg>/', '', $timeElement->innertext));
                $item['timestamp'] = strtotime($timeText) ?: time();
            }

            // Автор
            if ($authorElement = $article->find('a[href*="/author/"]', 0)) {
                $item['author'] = trim($authorElement->plaintext);
            }

            // Базовый контент (анонс)
            $content = $article->find('div.card-text div.d-flex.d-sm-block', 0)->plaintext ?? '';

            // Загрузка полного текста (если включено)
            if ($this->getInput('full_content')) {
                if ($fullText = $this->loadFullContent($item['uri'])) {
                    $content = $fullText;
                } else {
                    $content .= "<p><em>Не удалось загрузить полный текст</em></p>";
                }
            }

            // Изображение (ленивая загрузка)
            if ($imageElement = $article->find('div.card-image-background img', 0)) {
                $imageSrc = $imageElement->src;
                $imageUrl = (strpos($imageSrc, 'http') === 0) ? $imageSrc : self::URI . $imageSrc;
                $content = "<img src='{$imageUrl}' alt='{$item['title']}' loading='lazy'><br>{$content}";
            }

            $item['content'] = $content;
            $item['categories'] = [ucfirst($section)];
            $this->items[] = $item;
        }
    }
}
