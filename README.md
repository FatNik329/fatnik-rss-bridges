
# FatNik-RSS-Bridges

Репозиторий предназначен для хранения личных самописных PHP скриптов (мосты) для генерации RSS лент. Включенные мосты используются self-hosted сервисом **[rss-bridge](https://github.com/RSS-Bridge/rss-bridge)**.


## Описание мостов
> [!IMPORTANT] 
> При указании получаемых количества статей - не стоит гнаться за большим значением. Некоторые из ресурсов блокируют доступ на определённый срок времени, если количество статей большое.

- [GameGuruBridge](https://gameguru.ru) - новости игр с фильтрацией по рубрикам;
- [GismeteoNewsBridge](https://www.gismeteo.ru/news/) - новости с Gismeteo;
- [HiTechMailBridge](https://hi-tech.mail.ru) - новости технологий с Hi-Tech.Mail.ru
- [IxbtBridge](https://www.ixbt.com/) - новости и обзоры с iXBT.com
- [IxbtGamesBridge](https://ixbt.games) - публикации iXBT.Games по категориям
- [IxbtLiveBridge](https://www.ixbt.com/live/) - новости с iXBT Live
- [MySKUBridge](https://mysku.club) - обзоры, статьи и скидки с MySKU.club
- [RutabBridge](https://rutab.net) - новости Rutab.net по категориям;
- [SravniMagBridge](https://www.sravni.ru/mag) - журнальные статьи Sravni.ru
- [VGTimesUniversalBridge](https://vgtimes.ru) - VGTimes.ru с поддержкой основных разделов

### Установка
1. [Закинуть PHP](https://rss-bridge.github.io/rss-bridge/Bridge_API/How_to_create_a_new_bridge.html)  скрипт в директорию сервиса **``bridges``**;
2. [Указать в файле](https://rss-bridge.github.io/rss-bridge/For_Hosts/Whitelisting.html) **``whitelist.txt``** имя используемого моста.
