<?php
class SravniMagBridge extends BridgeAbstract {
    const NAME = 'Sravni.ru';
    const URI = 'https://www.sravni.ru/mag';
    const DESCRIPTION = 'Журнал - статьи о финансах и жизни с Sravni.ru';
    const MAINTAINER = 'FatNik';
    const CACHE_TIMEOUT = 3600;

    const PARAMETERS = [
        [
            'limit' => [
                'name' => 'Лимит статей',
                'type' => 'number',
                'defaultValue' => 5,
                'title' => 'Количество статей для загрузки (максимум 10)'
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
            'Referer: https://www.sravni.ru/',
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

            // Основной контент статьи
            $content = $html->find('div.article-content', 0);
            if (!$content) return 'Полный текст не найден';

            // Находим и обрабатываем все изображения в статье
            foreach ($content->find('img') as $img) {
                $src = $img->src;
                if (strpos($src, 'http') !== 0) {
                    $src = 'https://www.sravni.ru' . ltrim($src, '/');
                }
                $img->outertext = '<p><img src="' . $src . '" style="max-width:100%"></p>';
            }

            // Удаляем ненужные элементы
            foreach ($content->find('div.ad, div.teaser, script, style') as $elem) {
                $elem->remove();
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
            // Парсим данные из страницы
            $html = $this->getHtmlWithUserAgent(self::URI);
            $jsonLd = $html->find('script[type="application/ld+json"]', 0);

            if (!$jsonLd) throw new Exception("Не удалось найти данные статей");

            $data = json_decode($jsonLd->innertext, true);
            if (!$data || !isset($data['mainEntity']['itemListElement'])) {
                throw new Exception("Неверный формат данных статей");
            }

            $articles = $data['mainEntity']['itemListElement'];
            $count = 0;

            foreach ($articles as $articleData) {
                if ($count >= $limit) break;

                $article = $articleData['@type'] === 'Article' ? $articleData : null;
                if (!$article) continue;

                $title = $article['headline'] ?? 'Без названия';
                $url = $article['url'];
                $description = $article['description'] ?? '';
                $image = $article['thumbnailUrl'] ?? $article['image'] ?? null;

                // Дата публикации
                $date = DateTime::createFromFormat(DateTime::ATOM, $article['datePublished']);
                $timestamp = $date ? $date->getTimestamp() : time();

                // Автор
                $author = $article['author']['name'] ?? '';

                // Формируем контент
                $content = '';
                // Добавляем основное изображение в начало
                if ($image) {
                    $content .= '<div class="article-image">';
                    $content .= '<img src="' . $image . '" alt="' . htmlspecialchars($title) . '" style="max-width:100%; height:auto; display:block; margin:0 auto">';
                    $content .= '</div>';
                }

                if ($author) {
                    $content .= '<p class="article-author"><em>Автор: ' . $author . '</em></p>';
                }

                $content .= '<div class="article-description">' . $description . '</div>';

                if ($loadFullText) {
                    $fullText = $this->getFullArticleText($url);
                    $content .= '<div class="article-fulltext">' . $fullText . '</div>';
                }

                $this->items[] = [
                    'title' => $title,
                    'uri' => $url,
                    'timestamp' => $timestamp,
                    'content' => $content,
                    // Оставляем вложения для совместимости
                    'author' => $author
                ];

                $count++;
            }

        } catch (Exception $e) {
            $this->items[] = [
                'title' => 'Ошибка загрузки Sravni.ru',
                'uri' => self::URI,
                'timestamp' => time(),
                'content' => 'Произошла ошибка: ' . $e->getMessage()
            ];
        }
    }
}
