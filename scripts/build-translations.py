#!/usr/bin/env python3
"""Build complete menagerie-{locale}.po/.mo files from languages/menagerie.pot."""

from __future__ import annotations

import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
POT = ROOT / "languages" / "menagerie.pot"
LANG_DIR = ROOT / "languages"

# msgid -> { locale: msgstr }
# Locales: es_ES, fr_FR, de_DE, nl_NL, ru_RU, zh_CN, ja
T: dict[str, dict[str, str]] = {}


def add(en: str, **langs: str) -> None:
	T[en] = langs


add(
	"https://wilcosky.com",
	es_ES="https://wilcosky.com",
	fr_FR="https://wilcosky.com",
	de_DE="https://wilcosky.com",
	nl_NL="https://wilcosky.com",
	ru_RU="https://wilcosky.com",
	zh_CN="https://wilcosky.com",
	ja="https://wilcosky.com",
)
add(
	"Billy Wilcosky",
	es_ES="Billy Wilcosky",
	fr_FR="Billy Wilcosky",
	de_DE="Billy Wilcosky",
	nl_NL="Billy Wilcosky",
	ru_RU="Billy Wilcosky",
	zh_CN="Billy Wilcosky",
	ja="Billy Wilcosky",
)
add(
	"Optional WebP/AVIF upload conversion, JPEG companions, picture/OG fallback, and bulk library conversion for WordPress 7.1+.",
	es_ES="Conversión opcional de subidas a WebP/AVIF, compañeros JPEG, fallback picture/OG y conversión masiva de la biblioteca para WordPress 7.1+.",
	fr_FR="Conversion facultative des envois en WebP/AVIF, compagnons JPEG, secours picture/OG et conversion en masse de la médiathèque pour WordPress 7.1+.",
	de_DE="Optionale WebP/AVIF-Upload-Konvertierung, JPEG-Begleiter, Picture/OG-Fallback und Massenumwandlung der Mediathek für WordPress 7.1+.",
	nl_NL="Optionele WebP/AVIF-uploadconversie, JPEG-companions, picture/OG-fallback en bulkconversie van de mediabibliotheek voor WordPress 7.1+.",
	ru_RU="Опциональное преобразование загрузок в WebP/AVIF, JPEG-компаньоны, запасной picture/OG и массовое преобразование медиатеки для WordPress 7.1+.",
	zh_CN="可选的 WebP/AVIF 上传转换、JPEG 配套、picture/OG 回退以及媒体库批量转换，适用于 WordPress 7.1+。",
	ja="任意の WebP/AVIF アップロード変換、JPEG コンパニオン、picture/OG フォールバック、メディアライブラリ一括変換（WordPress 7.1+）。",
)
add(
	"Invalid target format.",
	es_ES="Formato de destino no válido.",
	fr_FR="Format cible non valide.",
	de_DE="Ungültiges Zielformat.",
	nl_NL="Ongeldig doelformaat.",
	ru_RU="Недопустимый целевой формат.",
	zh_CN="无效的目标格式。",
	ja="無効な変換先形式です。",
)
add(
	"This host cannot encode %s.",
	es_ES="Este servidor no puede codificar %s.",
	fr_FR="Cet hébergeur ne peut pas encoder %s.",
	de_DE="Dieser Host kann %s nicht kodieren.",
	nl_NL="Deze host kan %s niet coderen.",
	ru_RU="Этот хост не может кодировать %s.",
	zh_CN="此主机无法编码 %s。",
	ja="このホストは %s をエンコードできません。",
)
add(
	"Unexpected error while converting this image.",
	es_ES="Error inesperado al convertir esta imagen.",
	fr_FR="Erreur inattendue lors de la conversion de cette image.",
	de_DE="Unerwarteter Fehler beim Konvertieren dieses Bildes.",
	nl_NL="Onverwachte fout bij het converteren van deze afbeelding.",
	ru_RU="Непредвиденная ошибка при преобразовании этого изображения.",
	zh_CN="转换此图片时发生意外错误。",
	ja="この画像の変換中に予期しないエラーが発生しました。",
)
add(
	"Attachment #%d",
	es_ES="Adjunto #%d",
	fr_FR="Média #%d",
	de_DE="Anhang #%d",
	nl_NL="Bijlage #%d",
	ru_RU="Вложение #%d",
	zh_CN="附件 #%d",
	ja="添付ファイル #%d",
)
add(
	"Unknown error.",
	es_ES="Error desconocido.",
	fr_FR="Erreur inconnue.",
	de_DE="Unbekannter Fehler.",
	nl_NL="Onbekende fout.",
	ru_RU="Неизвестная ошибка.",
	zh_CN="未知错误。",
	ja="不明なエラーです。",
)
add(
	"Image Converter",
	es_ES="Convertidor de imágenes",
	fr_FR="Convertisseur d’images",
	de_DE="Bildkonverter",
	nl_NL="Afbeeldingsconverter",
	ru_RU="Конвертер изображений",
	zh_CN="图片转换器",
	ja="画像コンバーター",
)
add(
	"Convert existing Media Library images to one format. Converter quality and “Convert when useful” follow Settings → Menagerie. GIF and SVG files are skipped. JPEG flattens transparency. When JPEG companion is enabled, AVIF/WebP conversions also generate JPEG companions (full and sized when enabled) as public files, not Media Library items. Images already in the target format are skipped.",
	es_ES="Convierte las imágenes existentes de la biblioteca de medios a un solo formato. La calidad del convertidor y “Convertir cuando sea útil” siguen Ajustes → Menagerie. Los archivos GIF y SVG se omiten. JPEG aplana la transparencia. Cuando el compañero JPEG está activado, las conversiones AVIF/WebP también generan compañeros JPEG (completos y por tamaño si está activado) como archivos públicos, no como elementos de la biblioteca de medios. Las imágenes que ya están en el formato de destino se omiten.",
	fr_FR="Convertit les images existantes de la médiathèque vers un format unique. La qualité du convertisseur et « Convertir si utile » suivent Réglages → Menagerie. Les fichiers GIF et SVG sont ignorés. Le JPEG aplatit la transparence. Lorsque le compagnon JPEG est activé, les conversions AVIF/WebP génèrent aussi des compagnons JPEG (plein format et tailles si activé) en fichiers publics, pas en éléments de la médiathèque. Les images déjà au format cible sont ignorées.",
	de_DE="Konvertiert vorhandene Mediathek-Bilder in ein Format. Konverterqualität und „Nur konvertieren, wenn sinnvoll“ folgen Einstellungen → Menagerie. GIF- und SVG-Dateien werden übersprungen. JPEG flacht Transparenz ab. Wenn der JPEG-Begleiter aktiviert ist, erzeugen AVIF/WebP-Konvertierungen auch JPEG-Begleiter (voll und in Zwischengrößen, falls aktiviert) als öffentliche Dateien, nicht als Mediathek-Einträge. Bilder, die bereits im Zielformat vorliegen, werden übersprungen.",
	nl_NL="Converteer bestaande mediabibliotheek-afbeeldingen naar één formaat. Converterkwaliteit en “Converteren wanneer nuttig” volgen Instellingen → Menagerie. GIF- en SVG-bestanden worden overgeslagen. JPEG vlakt transparantie af. Als JPEG-companion is ingeschakeld, genereren AVIF/WebP-conversies ook JPEG-companions (volledig en op formaat indien ingeschakeld) als openbare bestanden, geen mediabibliotheek-items. Afbeeldingen die al in het doelformaat staan, worden overgeslagen.",
	ru_RU="Преобразует существующие изображения медиатеки в один формат. Качество конвертера и «Преобразовывать, когда полезно» берутся из Настройки → Menagerie. GIF и SVG пропускаются. JPEG убирает прозрачность. Если JPEG-компаньон включён, преобразования в AVIF/WebP также создают JPEG-компаньоны (полный и размеры при включении) как публичные файлы, а не элементы медиатеки. Изображения, уже находящиеся в целевом формате, пропускаются.",
	zh_CN="将媒体库中的现有图片转换为一种格式。转换器质量以及“有益时才转换”遵循 设置 → Menagerie。会跳过 GIF 和 SVG。JPEG 会扁平化透明区域。启用 JPEG 配套时，AVIF/WebP 转换还会生成 JPEG 配套（完整及启用时的各尺寸）作为公开文件，而不是媒体库项目。已是目标格式的图片会跳过。",
	ja="メディアライブラリの既存画像を1つの形式に変換します。変換品質と「有用な場合に変換」は 設定 → Menagerie に従います。GIF と SVG はスキップされます。JPEG は透明度を平坦化します。JPEG コンパニオンが有効な場合、AVIF/WebP 変換では JPEG コンパニオン（フルおよび有効時は各サイズ）もメディアライブラリ項目ではなく公開ファイルとして生成されます。すでに変換先形式の画像はスキップされます。",
)
add(
	"This host cannot encode: %s. Those options are disabled. Check Tools → Site Health → Info → Media handling.",
	es_ES="Este servidor no puede codificar: %s. Esas opciones están desactivadas. Revisa Herramientas → Salud del sitio → Información → Gestión de medios.",
	fr_FR="Cet hébergeur ne peut pas encoder : %s. Ces options sont désactivées. Vérifiez Outils → Santé du site → Infos → Gestion des médias.",
	de_DE="Dieser Host kann nicht kodieren: %s. Diese Optionen sind deaktiviert. Prüfen Sie Werkzeuge → Website-Zustand → Info → Medienverarbeitung.",
	nl_NL="Deze host kan niet coderen: %s. Die opties zijn uitgeschakeld. Controleer Gereedschap → Sitegezondheid → Info → Mediaverwerking.",
	ru_RU="Этот хост не может кодировать: %s. Эти параметры отключены. Проверьте Инструменты → Здоровье сайта → Сведения → Обработка медиафайлов.",
	zh_CN="此主机无法编码：%s。这些选项已禁用。请查看 工具 → 站点健康 → 信息 → 媒体处理。",
	ja="このホストはエンコードできません: %s。それらのオプションは無効です。ツール → サイトヘルス → 情報 → メディア処理 を確認してください。",
)
add(
	"Convert to",
	es_ES="Convertir a",
	fr_FR="Convertir en",
	de_DE="Konvertieren nach",
	nl_NL="Converteren naar",
	ru_RU="Преобразовать в",
	zh_CN="转换为",
	ja="変換先",
)
add(
	"Convert library",
	es_ES="Convertir biblioteca",
	fr_FR="Convertir la médiathèque",
	de_DE="Mediathek konvertieren",
	nl_NL="Bibliotheek converteren",
	ru_RU="Преобразовать библиотеку",
	zh_CN="转换媒体库",
	ja="ライブラリを変換",
)
add(
	"No convertible formats are available on this host.",
	es_ES="No hay formatos convertibles disponibles en este servidor.",
	fr_FR="Aucun format convertible n’est disponible sur cet hébergeur.",
	de_DE="Auf diesem Host sind keine konvertierbaren Formate verfügbar.",
	nl_NL="Er zijn geen converteerbare formaten beschikbaar op deze host.",
	ru_RU="На этом хосте нет доступных форматов для преобразования.",
	zh_CN="此主机上没有可用的可转换格式。",
	ja="このホストでは変換可能な形式がありません。",
)
add(
	"Upload output is set to %s, but this server cannot encode that format. Chrome/Edge with client-side media processing can still convert in the browser; other browsers fall back to the server and may leave JPEG/PNG unchanged. Check Tools → Site Health → Info → Media handling.",
	es_ES="La salida de subida está configurada en %s, pero este servidor no puede codificar ese formato. Chrome/Edge con procesamiento de medios en el cliente aún pueden convertir en el navegador; otros navegadores recurren al servidor y pueden dejar JPEG/PNG sin cambios. Revisa Herramientas → Salud del sitio → Información → Gestión de medios.",
	fr_FR="La sortie d’envoi est réglée sur %s, mais ce serveur ne peut pas encoder ce format. Chrome/Edge avec le traitement média côté client peuvent encore convertir dans le navigateur ; les autres navigateurs basculent sur le serveur et peuvent laisser les JPEG/PNG inchangés. Vérifiez Outils → Santé du site → Infos → Gestion des médias.",
	de_DE="Die Upload-Ausgabe ist auf %s eingestellt, aber dieser Server kann dieses Format nicht kodieren. Chrome/Edge mit clientseitiger Medienverarbeitung können weiterhin im Browser konvertieren; andere Browser fallen auf den Server zurück und lassen JPEG/PNG möglicherweise unverändert. Prüfen Sie Werkzeuge → Website-Zustand → Info → Medienverarbeitung.",
	nl_NL="Uploaduitvoer staat op %s, maar deze server kan dat formaat niet coderen. Chrome/Edge met client-side mediaverwerking kunnen nog in de browser converteren; andere browsers vallen terug op de server en laten JPEG/PNG mogelijk ongewijzigd. Controleer Gereedschap → Sitegezondheid → Info → Mediaverwerking.",
	ru_RU="Выходной формат загрузки — %s, но этот сервер не может его кодировать. Chrome/Edge с клиентской обработкой медиа всё ещё могут преобразовывать в браузере; другие браузеры переходят на сервер и могут оставить JPEG/PNG без изменений. Проверьте Инструменты → Здоровье сайта → Сведения → Обработка медиафайлов.",
	zh_CN="上传输出已设为 %s，但此服务器无法编码该格式。支持客户端媒体处理的 Chrome/Edge 仍可在浏览器中转换；其他浏览器会回退到服务器，并可能保持 JPEG/PNG 不变。请查看 工具 → 站点健康 → 信息 → 媒体处理。",
	ja="アップロード出力は %s に設定されていますが、このサーバーはその形式をエンコードできません。クライアント側メディア処理対応の Chrome/Edge はブラウザで変換できます。他のブラウザはサーバーにフォールバックし、JPEG/PNG のままになる場合があります。ツール → サイトヘルス → 情報 → メディア処理 を確認してください。",
)
add(
	"This server cannot encode JPEG. JPEG companions and converter JPEG output will fail until GD or ImageMagick supports JPEG.",
	es_ES="Este servidor no puede codificar JPEG. Los compañeros JPEG y la salida JPEG del convertidor fallarán hasta que GD o ImageMagick admitan JPEG.",
	fr_FR="Ce serveur ne peut pas encoder le JPEG. Les compagnons JPEG et la sortie JPEG du convertisseur échoueront tant que GD ou ImageMagick ne prendront pas en charge le JPEG.",
	de_DE="Dieser Server kann JPEG nicht kodieren. JPEG-Begleiter und JPEG-Ausgabe des Konverters schlagen fehl, bis GD oder ImageMagick JPEG unterstützen.",
	nl_NL="Deze server kan JPEG niet coderen. JPEG-companions en JPEG-uitvoer van de converter mislukken totdat GD of ImageMagick JPEG ondersteunt.",
	ru_RU="Этот сервер не может кодировать JPEG. JPEG-компаньоны и JPEG-вывод конвертера будут сбоить, пока GD или ImageMagick не поддерживают JPEG.",
	zh_CN="此服务器无法编码 JPEG。在 GD 或 ImageMagick 支持 JPEG 之前，JPEG 配套和转换器的 JPEG 输出都会失败。",
	ja="このサーバーは JPEG をエンコードできません。GD または ImageMagick が JPEG に対応するまで、JPEG コンパニオンとコンバーターの JPEG 出力は失敗します。",
)
add(
	"This server may not decode %s. JPEG companions for those uploads may fail. Check Tools → Site Health → Info → Media handling.",
	es_ES="Este servidor puede no decodificar %s. Los compañeros JPEG de esas subidas pueden fallar. Revisa Herramientas → Salud del sitio → Información → Gestión de medios.",
	fr_FR="Ce serveur peut ne pas décoder %s. Les compagnons JPEG de ces envois peuvent échouer. Vérifiez Outils → Santé du site → Infos → Gestion des médias.",
	de_DE="Dieser Server kann %s möglicherweise nicht dekodieren. JPEG-Begleiter für diese Uploads können fehlschlagen. Prüfen Sie Werkzeuge → Website-Zustand → Info → Medienverarbeitung.",
	nl_NL="Deze server kan %s mogelijk niet decoderen. JPEG-companions voor die uploads kunnen mislukken. Controleer Gereedschap → Sitegezondheid → Info → Mediaverwerking.",
	ru_RU="Этот сервер может не декодировать %s. JPEG-компаньоны для таких загрузок могут не создаться. Проверьте Инструменты → Здоровье сайта → Сведения → Обработка медиафайлов.",
	zh_CN="此服务器可能无法解码 %s。这些上传的 JPEG 配套可能会失败。请查看 工具 → 站点健康 → 信息 → 媒体处理。",
	ja="このサーバーは %s をデコードできない可能性があります。それらのアップロードの JPEG コンパニオンは失敗する場合があります。ツール → サイトヘルス → 情報 → メディア処理 を確認してください。",
)
add(
	"Menagerie",
	es_ES="Menagerie",
	fr_FR="Menagerie",
	de_DE="Menagerie",
	nl_NL="Menagerie",
	ru_RU="Menagerie",
	zh_CN="Menagerie",
	ja="Menagerie",
)
add(
	"WordPress 7.1+ handles upload encoding and thumbnails. Menagerie adds JPEG companions for older browsers and social previews, plus Tools → Image Converter for bulk library conversion.",
	es_ES="WordPress 7.1+ gestiona la codificación de subidas y las miniaturas. Menagerie añade compañeros JPEG para navegadores antiguos y vistas previas sociales, además de Herramientas → Convertidor de imágenes para conversión masiva.",
	fr_FR="WordPress 7.1+ gère l’encodage des envois et les miniatures. Menagerie ajoute des compagnons JPEG pour les navigateurs plus anciens et les aperçus sociaux, plus Outils → Convertisseur d’images pour la conversion en masse.",
	de_DE="WordPress 7.1+ übernimmt Upload-Kodierung und Miniaturansichten. Menagerie fügt JPEG-Begleiter für ältere Browser und Social-Previews hinzu sowie Werkzeuge → Bildkonverter für die Massenkonvertierung.",
	nl_NL="WordPress 7.1+ regelt uploadcodering en miniaturen. Menagerie voegt JPEG-companions toe voor oudere browsers en sociale previews, plus Gereedschap → Afbeeldingsconverter voor bulkconversie.",
	ru_RU="WordPress 7.1+ обрабатывает кодирование загрузок и миниатюры. Menagerie добавляет JPEG-компаньоны для старых браузеров и соцсетей, а также Инструменты → Конвертер изображений для пакетного преобразования.",
	zh_CN="WordPress 7.1+ 负责上传编码和缩略图。Menagerie 为旧版浏览器和社交预览添加 JPEG 配套，并提供 工具 → 图片转换器 用于批量转换媒体库。",
	ja="WordPress 7.1+ がアップロードのエンコードとサムネイルを処理します。Menagerie は古いブラウザやソーシャルプレビュー向けの JPEG コンパニオンと、一括変換用の ツール → 画像コンバーター を追加します。",
)
add(
	"Upload output format",
	es_ES="Formato de salida al subir",
	fr_FR="Format de sortie à l’envoi",
	de_DE="Upload-Ausgabeformat",
	nl_NL="Uploaduitvoerformaat",
	ru_RU="Формат вывода при загрузке",
	zh_CN="上传输出格式",
	ja="アップロード出力形式",
)
add(
	"Keep as uploaded (WordPress default)",
	es_ES="Mantener como se subió (predeterminado de WordPress)",
	fr_FR="Conserver tel quel (défaut WordPress)",
	de_DE="Wie hochgeladen belassen (WordPress-Standard)",
	nl_NL="Behouden zoals geüpload (WordPress-standaard)",
	ru_RU="Оставить как загружено (по умолчанию WordPress)",
	zh_CN="保持上传原样（WordPress 默认）",
	ja="アップロードのまま（WordPress の既定）",
)
add(
	"Convert JPEG/PNG to WebP",
	es_ES="Convertir JPEG/PNG a WebP",
	fr_FR="Convertir JPEG/PNG en WebP",
	de_DE="JPEG/PNG nach WebP konvertieren",
	nl_NL="JPEG/PNG naar WebP converteren",
	ru_RU="Преобразовать JPEG/PNG в WebP",
	zh_CN="将 JPEG/PNG 转换为 WebP",
	ja="JPEG/PNG を WebP に変換",
)
add(
	"Convert JPEG/PNG to AVIF",
	es_ES="Convertir JPEG/PNG a AVIF",
	fr_FR="Convertir JPEG/PNG en AVIF",
	de_DE="JPEG/PNG nach AVIF konvertieren",
	nl_NL="JPEG/PNG naar AVIF converteren",
	ru_RU="Преобразовать JPEG/PNG в AVIF",
	zh_CN="将 JPEG/PNG 转换为 AVIF",
	ja="JPEG/PNG を AVIF に変換",
)
add(
	"Uses WordPress’s native image_editor_output_format filter. Client-side media processing (Chrome/Edge) and server uploads both honor this. Pair with JPEG companion below for older browsers and social previews.",
	es_ES="Usa el filtro nativo image_editor_output_format de WordPress. El procesamiento de medios en el cliente (Chrome/Edge) y las subidas al servidor lo respetan. Combínalo con el compañero JPEG de abajo para navegadores antiguos y vistas previas sociales.",
	fr_FR="Utilise le filtre natif image_editor_output_format de WordPress. Le traitement média côté client (Chrome/Edge) et les envois serveur le respectent. Associez-le au compagnon JPEG ci-dessous pour les navigateurs plus anciens et les aperçus sociaux.",
	de_DE="Nutzt den nativen WordPress-Filter image_editor_output_format. Clientseitige Medienverarbeitung (Chrome/Edge) und Server-Uploads berücksichtigen ihn. Kombinieren Sie ihn mit dem JPEG-Begleiter unten für ältere Browser und Social-Previews.",
	nl_NL="Gebruikt het native WordPress-filter image_editor_output_format. Client-side mediaverwerking (Chrome/Edge) en serveruploads respecteren dit. Combineer met de JPEG-companion hieronder voor oudere browsers en sociale previews.",
	ru_RU="Использует нативный фильтр WordPress image_editor_output_format. Клиентская обработка медиа (Chrome/Edge) и серверные загрузки его учитывают. Сочетайте с JPEG-компаньоном ниже для старых браузеров и соцсетей.",
	zh_CN="使用 WordPress 原生的 image_editor_output_format 过滤器。客户端媒体处理（Chrome/Edge）与服务器上传都会遵守。可与下方的 JPEG 配套配合，用于旧版浏览器和社交预览。",
	ja="WordPress 純正の image_editor_output_format フィルターを使用します。クライアント側メディア処理（Chrome/Edge）とサーバーアップロードの両方がこれに従います。古いブラウザやソーシャルプレビュー向けに、下の JPEG コンパニオンと併用してください。",
)
add(
	"JPEG companion",
	es_ES="Compañero JPEG",
	fr_FR="Compagnon JPEG",
	de_DE="JPEG-Begleiter",
	nl_NL="JPEG-companion",
	ru_RU="JPEG-компаньон",
	zh_CN="JPEG 配套",
	ja="JPEG コンパニオン",
)
add(
	"When an attachment is AVIF or WebP, generate a JPEG companion in uploads (not a Media Library item) for older browsers and social previews. Transparent areas are flattened onto the background color below.",
	es_ES="Cuando un adjunto es AVIF o WebP, genera un compañero JPEG en uploads (no un elemento de la biblioteca de medios) para navegadores antiguos y vistas previas sociales. Las áreas transparentes se aplanan sobre el color de fondo de abajo.",
	fr_FR="Lorsqu’un média est AVIF ou WebP, génère un compagnon JPEG dans uploads (pas un élément de la médiathèque) pour les navigateurs plus anciens et les aperçus sociaux. Les zones transparentes sont aplaties sur la couleur d’arrière-plan ci-dessous.",
	de_DE="Wenn ein Anhang AVIF oder WebP ist, wird ein JPEG-Begleiter in uploads erzeugt (kein Mediathek-Eintrag) für ältere Browser und Social-Previews. Transparente Bereiche werden auf die Hintergrundfarbe unten abgeflacht.",
	nl_NL="Als een bijlage AVIF of WebP is, genereer een JPEG-companion in uploads (geen mediabibliotheek-item) voor oudere browsers en sociale previews. Transparante gebieden worden afgevlakt op de achtergrondkleur hieronder.",
	ru_RU="Если вложение AVIF или WebP, создаёт JPEG-компаньон в uploads (не элемент медиатеки) для старых браузеров и соцсетей. Прозрачные области заливаются цветом фона ниже.",
	zh_CN="当附件为 AVIF 或 WebP 时，在 uploads 中生成 JPEG 配套（不是媒体库项目），供旧版浏览器和社交预览使用。透明区域会扁平化到下方的背景色上。",
	ja="添付ファイルが AVIF または WebP の場合、古いブラウザやソーシャルプレビュー向けに uploads 内へ JPEG コンパニオン（メディライブラリ項目ではありません）を生成します。透明部分は下の背景色で平坦化されます。",
)
add(
	"JPEG companion quality (1-100)",
	es_ES="Calidad del compañero JPEG (1-100)",
	fr_FR="Qualité du compagnon JPEG (1-100)",
	de_DE="JPEG-Begleiter-Qualität (1–100)",
	nl_NL="JPEG-companionkwaliteit (1-100)",
	ru_RU="Качество JPEG-компаньона (1–100)",
	zh_CN="JPEG 配套质量（1-100）",
	ja="JPEG コンパニオン品質（1〜100）",
)
add(
	"Default 75. Used only for companion JPEGs.",
	es_ES="Predeterminado 75. Solo se usa para los JPEG compañeros.",
	fr_FR="Par défaut 75. Utilisé uniquement pour les JPEG compagnons.",
	de_DE="Standard 75. Nur für Begleit-JPEGs.",
	nl_NL="Standaard 75. Alleen voor companion-JPEG’s.",
	ru_RU="По умолчанию 75. Только для JPEG-компаньонов.",
	zh_CN="默认 75。仅用于配套 JPEG。",
	ja="既定は 75。コンパニオン JPEG のみに使用します。",
)
add(
	"JPEG companion background",
	es_ES="Fondo del compañero JPEG",
	fr_FR="Arrière-plan du compagnon JPEG",
	de_DE="JPEG-Begleiter-Hintergrund",
	nl_NL="JPEG-companionachtergrond",
	ru_RU="Фон JPEG-компаньона",
	zh_CN="JPEG 配套背景",
	ja="JPEG コンパニオン背景",
)
add(
	"Hex color used when flattening transparency (default #ffffff).",
	es_ES="Color hexadecimal usado al aplanar la transparencia (predeterminado #ffffff).",
	fr_FR="Couleur hexadécimale utilisée pour aplatir la transparence (défaut #ffffff).",
	de_DE="Hex-Farbe beim Abflachen von Transparenz (Standard #ffffff).",
	nl_NL="Hex-kleur bij het afvlakken van transparantie (standaard #ffffff).",
	ru_RU="HEX-цвет при заливке прозрачности (по умолчанию #ffffff).",
	zh_CN="扁平化透明时使用的十六进制颜色（默认 #ffffff）。",
	ja="透明度を平坦化するときに使う HEX 色（既定 #ffffff）。",
)
add(
	"JPEG companion max long edge (px)",
	es_ES="Borde largo máximo del compañero JPEG (px)",
	fr_FR="Bord long max du compagnon JPEG (px)",
	de_DE="Max. lange Kante des JPEG-Begleiters (px)",
	nl_NL="Max. lange zijde JPEG-companion (px)",
	ru_RU="Макс. длинная сторона JPEG-компаньона (px)",
	zh_CN="JPEG 配套最长边（px）",
	ja="JPEG コンパニオン最長辺（px）",
)
add(
	"Cap the longest edge of companion JPEGs (default 2560).",
	es_ES="Limita el borde más largo de los JPEG compañeros (predeterminado 2560).",
	fr_FR="Limite le bord le plus long des JPEG compagnons (défaut 2560).",
	de_DE="Begrenzt die längste Kante der Begleit-JPEGs (Standard 2560).",
	nl_NL="Beperk de langste zijde van companion-JPEG’s (standaard 2560).",
	ru_RU="Ограничивает самую длинную сторону JPEG-компаньонов (по умолчанию 2560).",
	zh_CN="限制配套 JPEG 的最长边（默认 2560）。",
	ja="コンパニオン JPEG の最長辺を制限します（既定 2560）。",
)
add(
	"Sized JPEG companions",
	es_ES="Compañeros JPEG por tamaño",
	fr_FR="Compagnons JPEG par taille",
	de_DE="JPEG-Begleiter für Zwischengrößen",
	nl_NL="JPEG-companions per formaat",
	ru_RU="JPEG-компаньоны для размеров",
	zh_CN="各尺寸 JPEG 配套",
	ja="サイズ別 JPEG コンパニオン",
)
add(
	"Also generate JPEG companions for each intermediate size WordPress created for the attachment (used in picture fallback srcset).",
	es_ES="También genera compañeros JPEG para cada tamaño intermedio que WordPress creó para el adjunto (usado en el srcset de respaldo de picture).",
	fr_FR="Génère aussi des compagnons JPEG pour chaque taille intermédiaire créée par WordPress pour le média (utilisé dans le srcset de repli picture).",
	de_DE="Erzeugt auch JPEG-Begleiter für jede Zwischengröße, die WordPress für den Anhang erstellt hat (für picture-Fallback-srcset).",
	nl_NL="Genereer ook JPEG-companions voor elk tussenformaat dat WordPress voor de bijlage maakte (gebruikt in picture-fallback-srcset).",
	ru_RU="Также создаёт JPEG-компаньоны для каждого промежуточного размера вложения WordPress (для srcset запасного picture).",
	zh_CN="还为 WordPress 为该附件创建的每个中间尺寸生成 JPEG 配套（用于 picture 回退 srcset）。",
	ja="WordPress がその添付ファイル用に作成した各中間サイズの JPEG コンパニオンも生成します（picture フォールバックの srcset で使用）。",
)
add(
	"Automatic picture fallback",
	es_ES="Respaldo automático de picture",
	fr_FR="Repli picture automatique",
	de_DE="Automatischer picture-Fallback",
	nl_NL="Automatische picture-fallback",
	ru_RU="Автоматический запасной picture",
	zh_CN="自动 picture 回退",
	ja="自動 picture フォールバック",
)
add(
	"Wrap standard WordPress images that have a JPEG companion in a <picture> element so supporting browsers get AVIF/WebP and others get JPEG (with a JPEG srcset when sized companions exist).",
	es_ES="Envuelve las imágenes estándar de WordPress que tienen compañero JPEG en un elemento <picture> para que los navegadores compatibles reciban AVIF/WebP y los demás JPEG (con srcset JPEG si hay compañeros por tamaño).",
	fr_FR="Enveloppe les images WordPress standard ayant un compagnon JPEG dans un élément <picture> afin que les navigateurs compatibles reçoivent AVIF/WebP et les autres du JPEG (avec un srcset JPEG si des compagnons par taille existent).",
	de_DE="Umhüllt Standard-WordPress-Bilder mit JPEG-Begleiter in einem <picture>-Element, damit unterstützende Browser AVIF/WebP und andere JPEG erhalten (mit JPEG-srcset, wenn Größenbegleiter existieren).",
	nl_NL="Wikkel standaard WordPress-afbeeldingen met een JPEG-companion in een <picture>-element zodat ondersteunende browsers AVIF/WebP krijgen en anderen JPEG (met JPEG-srcset als er formaatcompanions zijn).",
	ru_RU="Оборачивает стандартные изображения WordPress с JPEG-компаньоном в элемент <picture>, чтобы поддерживающие браузеры получали AVIF/WebP, а остальные — JPEG (со srcset JPEG при наличии размерных компаньонов).",
	zh_CN="将带有 JPEG 配套的标准 WordPress 图片包裹在 <picture> 元素中，使支持的浏览器获得 AVIF/WebP，其他浏览器获得 JPEG（存在尺寸配套时带 JPEG srcset）。",
	ja="JPEG コンパニオンがある標準の WordPress 画像を <picture> 要素で包み、対応ブラウザには AVIF/WebP、それ以外には JPEG（サイズ別コンパニオンがある場合は JPEG の srcset）を届けます。",
)
add(
	"Open Graph uses JPEG companion",
	es_ES="Open Graph usa el compañero JPEG",
	fr_FR="Open Graph utilise le compagnon JPEG",
	de_DE="Open Graph nutzt JPEG-Begleiter",
	nl_NL="Open Graph gebruikt JPEG-companion",
	ru_RU="Open Graph использует JPEG-компаньон",
	zh_CN="Open Graph 使用 JPEG 配套",
	ja="Open Graph は JPEG コンパニオンを使用",
)
add(
	"Prefer the full-size JPEG companion for og:image when the SEO image maps to that attachment.",
	es_ES="Prefiere el compañero JPEG a tamaño completo para og:image cuando la imagen SEO corresponde a ese adjunto.",
	fr_FR="Préfère le compagnon JPEG pleine taille pour og:image lorsque l’image SEO correspond à ce média.",
	de_DE="Bevorzugt den vollständigen JPEG-Begleiter für og:image, wenn das SEO-Bild diesem Anhang zugeordnet ist.",
	nl_NL="Geef de voorkeur aan de JPEG-companion op volledige grootte voor og:image wanneer de SEO-afbeelding bij die bijlage hoort.",
	ru_RU="Предпочитает полноразмерный JPEG-компаньон для og:image, когда SEO-изображение соответствует этому вложению.",
	zh_CN="当 SEO 图片对应该附件时，优先将完整尺寸的 JPEG 配套用于 og:image。",
	ja="SEO 画像がその添付ファイルに対応する場合、og:image にはフルサイズの JPEG コンパニオンを優先します。",
)
add(
	"Upload toasts",
	es_ES="Avisos de subida",
	fr_FR="Toasts d’envoi",
	de_DE="Upload-Toasts",
	nl_NL="Uploadtoasts",
	ru_RU="Уведомления о загрузке",
	zh_CN="上传提示",
	ja="アップロードトースト",
)
add(
	"Show toasts in the backend (admin)",
	es_ES="Mostrar avisos en el backend (admin)",
	fr_FR="Afficher les toasts dans le back-office (admin)",
	de_DE="Toasts im Backend (Admin) anzeigen",
	nl_NL="Toasts tonen in de backend (admin)",
	ru_RU="Показывать уведомления в админке",
	zh_CN="在后台（管理）显示提示",
	ja="バックエンド（管理画面）でトーストを表示",
)
add(
	"Turn on or off status messages after Media Library and block editor uploads.",
	es_ES="Activa o desactiva los mensajes de estado tras subidas en la biblioteca de medios y el editor de bloques.",
	fr_FR="Active ou désactive les messages d’état après les envois de la médiathèque et de l’éditeur de blocs.",
	de_DE="Statusmeldungen nach Uploads in Mediathek und Block-Editor ein- oder ausschalten.",
	nl_NL="Statusberichten na uploads in de mediabibliotheek en de blok-editor in- of uitschakelen.",
	ru_RU="Включает или отключает сообщения о статусе после загрузок в медиатеке и редакторе блоков.",
	zh_CN="开启或关闭媒体库和区块编辑器上传后的状态消息。",
	ja="メディアライブラリとブロックエディターへのアップロード後のステータスメッセージをオン/オフします。",
)
add(
	"Show toasts on the frontend",
	es_ES="Mostrar avisos en el frontend",
	fr_FR="Afficher les toasts sur le front",
	de_DE="Toasts im Frontend anzeigen",
	nl_NL="Toasts tonen op de frontend",
	ru_RU="Показывать уведомления на сайте",
	zh_CN="在前台显示提示",
	ja="フロントエンドでトーストを表示",
)
add(
	"Turn on or off status messages after front-end uploads (for users who can upload files).",
	es_ES="Activa o desactiva los mensajes de estado tras subidas en el frontend (para usuarios que pueden subir archivos).",
	fr_FR="Active ou désactive les messages d’état après les envois côté front (pour les utilisateurs pouvant envoyer des fichiers).",
	de_DE="Statusmeldungen nach Frontend-Uploads ein- oder ausschalten (für Benutzer, die Dateien hochladen dürfen).",
	nl_NL="Statusberichten na frontend-uploads in- of uitschakelen (voor gebruikers die bestanden mogen uploaden).",
	ru_RU="Включает или отключает сообщения после загрузок на сайте (для пользователей с правом загрузки файлов).",
	zh_CN="开启或关闭前台上传后的状态消息（面向可上传文件的用户）。",
	ja="フロントエンドのアップロード後のステータスメッセージをオン/オフします（ファイルをアップロードできるユーザー向け）。",
)
add(
	"Toast message text",
	es_ES="Texto de los avisos",
	fr_FR="Texte des toasts",
	de_DE="Toast-Nachrichtentext",
	nl_NL="Toastberichttekst",
	ru_RU="Текст уведомлений",
	zh_CN="提示文案",
	ja="トーストメッセージ文言",
)
add(
	"Optional. Leave a field blank to use the default (translated when a language pack is available). Max 500 characters each.",
	es_ES="Opcional. Deja un campo en blanco para usar el valor predeterminado (traducido si hay un paquete de idioma). Máx. 500 caracteres cada uno.",
	fr_FR="Facultatif. Laissez un champ vide pour utiliser la valeur par défaut (traduite si un pack de langue est disponible). Max. 500 caractères chacun.",
	de_DE="Optional. Feld leer lassen für den Standard (übersetzt, wenn ein Sprachpaket verfügbar ist). Je max. 500 Zeichen.",
	nl_NL="Optioneel. Laat een veld leeg voor de standaard (vertaald als er een taalpakket is). Max. 500 tekens per veld.",
	ru_RU="Необязательно. Оставьте поле пустым для значения по умолчанию (переводится при наличии языкового пакета). Макс. 500 символов каждое.",
	zh_CN="可选。留空则使用默认文案（有语言包时会翻译）。每项最多 500 个字符。",
	ja="任意。空欄のままにすると既定文（言語パックがあれば翻訳）を使います。各 500 文字まで。",
)
add(
	"Converted to AVIF",
	es_ES="Convertido a AVIF",
	fr_FR="Converti en AVIF",
	de_DE="Nach AVIF konvertiert",
	nl_NL="Geconverteerd naar AVIF",
	ru_RU="Преобразовано в AVIF",
	zh_CN="已转换为 AVIF",
	ja="AVIF に変換済み",
)
add(
	"AVIF failed, used WebP",
	es_ES="AVIF falló, se usó WebP",
	fr_FR="AVIF échoué, WebP utilisé",
	de_DE="AVIF fehlgeschlagen, WebP verwendet",
	nl_NL="AVIF mislukt, WebP gebruikt",
	ru_RU="AVIF не удался, использован WebP",
	zh_CN="AVIF 失败，已改用 WebP",
	ja="AVIF 失敗、WebP を使用",
)
add(
	"Converted to WebP",
	es_ES="Convertido a WebP",
	fr_FR="Converti en WebP",
	de_DE="Nach WebP konvertiert",
	nl_NL="Geconverteerd naar WebP",
	ru_RU="Преобразовано в WebP",
	zh_CN="已转换为 WebP",
	ja="WebP に変換済み",
)
add(
	"Conversion failed",
	es_ES="Conversión fallida",
	fr_FR="Conversion échouée",
	de_DE="Konvertierung fehlgeschlagen",
	nl_NL="Conversie mislukt",
	ru_RU="Преобразование не удалось",
	zh_CN="转换失败",
	ja="変換に失敗",
)
add(
	"JPEG companion created",
	es_ES="Compañero JPEG creado",
	fr_FR="Compagnon JPEG créé",
	de_DE="JPEG-Begleiter erstellt",
	nl_NL="JPEG-companion gemaakt",
	ru_RU="JPEG-компаньон создан",
	zh_CN="已创建 JPEG 配套",
	ja="JPEG コンパニオンを作成",
)
add(
	"Dismiss button (accessibility)",
	es_ES="Botón descartar (accesibilidad)",
	fr_FR="Bouton fermer (accessibilité)",
	de_DE="Schließen-Schaltfläche (Barrierefreiheit)",
	nl_NL="Sluitknop (toegankelijkheid)",
	ru_RU="Кнопка закрытия (доступность)",
	zh_CN="关闭按钮（无访问性）",
	ja="閉じるボタン（アクセシビリティ）",
)
add(
	"Converter quality (1-100)",
	es_ES="Calidad del convertidor (1-100)",
	fr_FR="Qualité du convertisseur (1-100)",
	de_DE="Konverterqualität (1–100)",
	nl_NL="Converterkwaliteit (1-100)",
	ru_RU="Качество конвертера (1–100)",
	zh_CN="转换器质量（1-100）",
	ja="コンバーター品質（1〜100）",
)
add(
	"Used by Tools → Image Converter when encoding to AVIF, WebP, JPEG, or PNG.",
	es_ES="Lo usa Herramientas → Convertidor de imágenes al codificar a AVIF, WebP, JPEG o PNG.",
	fr_FR="Utilisé par Outils → Convertisseur d’images lors de l’encodage en AVIF, WebP, JPEG ou PNG.",
	de_DE="Wird von Werkzeuge → Bildkonverter beim Kodieren nach AVIF, WebP, JPEG oder PNG verwendet.",
	nl_NL="Gebruikt door Gereedschap → Afbeeldingsconverter bij coderen naar AVIF, WebP, JPEG of PNG.",
	ru_RU="Используется в Инструменты → Конвертер изображений при кодировании в AVIF, WebP, JPEG или PNG.",
	zh_CN="由 工具 → 图片转换器 在编码为 AVIF、WebP、JPEG 或 PNG 时使用。",
	ja="ツール → 画像コンバーター が AVIF、WebP、JPEG、PNG へエンコードするときに使用します。",
)
add(
	"Convert when useful",
	es_ES="Convertir cuando sea útil",
	fr_FR="Convertir si utile",
	de_DE="Nur konvertieren, wenn sinnvoll",
	nl_NL="Converteren wanneer nuttig",
	ru_RU="Преобразовывать, когда полезно",
	zh_CN="有益时才转换",
	ja="有用な場合に変換",
)
add(
	"Skip re-encoding in the Image Converter when the new file would not be smaller.",
	es_ES="Omite la recodificación en el Convertidor de imágenes cuando el archivo nuevo no sería más pequeño.",
	fr_FR="Ignore le réencodage dans le Convertisseur d’images lorsque le nouveau fichier ne serait pas plus petit.",
	de_DE="Überspringt das erneute Kodieren im Bildkonverter, wenn die neue Datei nicht kleiner wäre.",
	nl_NL="Sla hercodering in de Afbeeldingsconverter over als het nieuwe bestand niet kleiner zou zijn.",
	ru_RU="Пропускает перекодирование в Конвертере изображений, если новый файл не будет меньше.",
	zh_CN="如果新文件不会更小，则在图片转换器中跳过重新编码。",
	ja="新しいファイルが小さくならない場合、画像コンバーターでの再エンコードをスキップします。",
)
add(
	"Save Changes",
	es_ES="Guardar cambios",
	fr_FR="Enregistrer les modifications",
	de_DE="Änderungen speichern",
	nl_NL="Wijzigingen opslaan",
	ru_RU="Сохранить изменения",
	zh_CN="保存更改",
	ja="変更を保存",
)
add(
	"Starting…",
	es_ES="Iniciando…",
	fr_FR="Démarrage…",
	de_DE="Startet…",
	nl_NL="Bezig met starten…",
	ru_RU="Запуск…",
	zh_CN="正在开始…",
	ja="開始しています…",
)
add(
	"Converting…",
	es_ES="Convirtiendo…",
	fr_FR="Conversion…",
	de_DE="Konvertiert…",
	nl_NL="Bezig met converteren…",
	ru_RU="Преобразование…",
	zh_CN="正在转换…",
	ja="変換中…",
)
add(
	"Conversion finished.",
	es_ES="Conversión finalizada.",
	fr_FR="Conversion terminée.",
	de_DE="Konvertierung abgeschlossen.",
	nl_NL="Conversie voltooid.",
	ru_RU="Преобразование завершено.",
	zh_CN="转换完成。",
	ja="変換が完了しました。",
)
add(
	"Converting %1$s of %2$s…",
	es_ES="Convirtiendo %1$s de %2$s…",
	fr_FR="Conversion de %1$s sur %2$s…",
	de_DE="Konvertiere %1$s von %2$s…",
	nl_NL="%1$s van %2$s converteren…",
	ru_RU="Преобразование %1$s из %2$s…",
	zh_CN="正在转换第 %1$s 张，共 %2$s 张…",
	ja="%2$s 件中 %1$s 件を変換中…",
)
add(
	"Convert all Media Library images to the selected format? This cannot be undone.",
	es_ES="¿Convertir todas las imágenes de la biblioteca de medios al formato seleccionado? Esto no se puede deshacer.",
	fr_FR="Convertir toutes les images de la médiathèque vers le format sélectionné ? Cette action est irréversible.",
	de_DE="Alle Mediathek-Bilder in das ausgewählte Format konvertieren? Dies kann nicht rückgängig gemacht werden.",
	nl_NL="Alle mediabibliotheek-afbeeldingen converteren naar het geselecteerde formaat? Dit kan niet ongedaan worden gemaakt.",
	ru_RU="Преобразовать все изображения медиатеки в выбранный формат? Это действие нельзя отменить.",
	zh_CN="要将媒体库中的所有图片转换为所选格式吗？此操作无法撤销。",
	ja="メディアライブラリのすべての画像を選択した形式に変換しますか？この操作は元に戻せません。",
)
add(
	"No images found in the Media Library.",
	es_ES="No se encontraron imágenes en la biblioteca de medios.",
	fr_FR="Aucune image trouvée dans la médiathèque.",
	de_DE="Keine Bilder in der Mediathek gefunden.",
	nl_NL="Geen afbeeldingen gevonden in de mediabibliotheek.",
	ru_RU="В медиатеке не найдено изображений.",
	zh_CN="媒体库中未找到图片。",
	ja="メディアライブラリに画像が見つかりません。",
)
add(
	"Converted: %d",
	es_ES="Convertidos: %d",
	fr_FR="Convertis : %d",
	de_DE="Konvertiert: %d",
	nl_NL="Geconverteerd: %d",
	ru_RU="Преобразовано: %d",
	zh_CN="已转换：%d",
	ja="変換済み: %d",
)
add(
	"Skipped: %d",
	es_ES="Omitidos: %d",
	fr_FR="Ignorés : %d",
	de_DE="Übersprungen: %d",
	nl_NL="Overgeslagen: %d",
	ru_RU="Пропущено: %d",
	zh_CN="已跳过：%d",
	ja="スキップ: %d",
)
add(
	"Failed: %d",
	es_ES="Fallidos: %d",
	fr_FR="Échoués : %d",
	de_DE="Fehlgeschlagen: %d",
	nl_NL="Mislukt: %d",
	ru_RU="Ошибок: %d",
	zh_CN="失败：%d",
	ja="失敗: %d",
)
add(
	"Failed conversions",
	es_ES="Conversiones fallidas",
	fr_FR="Conversions échouées",
	de_DE="Fehlgeschlagene Konvertierungen",
	nl_NL="Mislukte conversies",
	ru_RU="Неудачные преобразования",
	zh_CN="转换失败",
	ja="失敗した変換",
)
add(
	"…and %d more.",
	es_ES="…y %d más.",
	fr_FR="…et %d de plus.",
	de_DE="…und %d weitere.",
	nl_NL="…en nog %d.",
	ru_RU="…и ещё %d.",
	zh_CN="…还有 %d 个。",
	ja="…他 %d 件。",
)
add(
	"You are not allowed to convert images.",
	es_ES="No tienes permiso para convertir imágenes.",
	fr_FR="Vous n’êtes pas autorisé à convertir des images.",
	de_DE="Sie dürfen keine Bilder konvertieren.",
	nl_NL="Je mag geen afbeeldingen converteren.",
	ru_RU="Вам не разрешено преобразовывать изображения.",
	zh_CN="你无权转换图片。",
	ja="画像を変換する権限がありません。",
)
add(
	"Security check failed. Reload the page and try again.",
	es_ES="La comprobación de seguridad falló. Recarga la página e inténtalo de nuevo.",
	fr_FR="Échec du contrôle de sécurité. Rechargez la page et réessayez.",
	de_DE="Sicherheitsprüfung fehlgeschlagen. Laden Sie die Seite neu und versuchen Sie es erneut.",
	nl_NL="Beveiligingscontrole mislukt. Laad de pagina opnieuw en probeer het opnieuw.",
	ru_RU="Проверка безопасности не пройдена. Обновите страницу и попробуйте снова.",
	zh_CN="安全检查失败。请重新加载页面后再试。",
	ja="セキュリティチェックに失敗しました。ページを再読み込みして再試行してください。",
)
add(
	"Already in the target format.",
	es_ES="Ya está en el formato de destino.",
	fr_FR="Déjà au format cible.",
	de_DE="Bereits im Zielformat.",
	nl_NL="Al in het doelformaat.",
	ru_RU="Уже в целевом формате.",
	zh_CN="已是目标格式。",
	ja="すでに変換先形式です。",
)
add(
	"Converted",
	es_ES="Convertidos",
	fr_FR="Convertis",
	de_DE="Konvertiert",
	nl_NL="Geconverteerd",
	ru_RU="Преобразовано",
	zh_CN="已转换",
	ja="変換済み",
)
add(
	"Skipped",
	es_ES="Omitidos",
	fr_FR="Ignorés",
	de_DE="Übersprungen",
	nl_NL="Overgeslagen",
	ru_RU="Пропущено",
	zh_CN="已跳过",
	ja="スキップ",
)
add(
	"Failed",
	es_ES="Fallidos",
	fr_FR="Échoués",
	de_DE="Fehlgeschlagen",
	nl_NL="Mislukt",
	ru_RU="Ошибки",
	zh_CN="失败",
	ja="失敗",
)
add(
	"Something went wrong. Please try again.",
	es_ES="Algo salió mal. Inténtalo de nuevo.",
	fr_FR="Une erreur s’est produite. Veuillez réessayer.",
	de_DE="Etwas ist schiefgelaufen. Bitte erneut versuchen.",
	nl_NL="Er is iets misgegaan. Probeer het opnieuw.",
	ru_RU="Что-то пошло не так. Попробуйте ещё раз.",
	zh_CN="出了点问题。请重试。",
	ja="問題が発生しました。もう一度お試しください。",
)
add(
	"Image converted to AVIF successfully and uploaded.",
	es_ES="Imagen convertida a AVIF correctamente y subida.",
	fr_FR="Image convertie en AVIF avec succès et envoyée.",
	de_DE="Bild erfolgreich nach AVIF konvertiert und hochgeladen.",
	nl_NL="Afbeelding succesvol naar AVIF geconverteerd en geüpload.",
	ru_RU="Изображение успешно преобразовано в AVIF и загружено.",
	zh_CN="图片已成功转换为 AVIF 并上传。",
	ja="画像を AVIF に変換してアップロードしました。",
)
add(
	"Image couldn't be converted to AVIF; converted to WebP instead and uploaded.",
	es_ES="No se pudo convertir la imagen a AVIF; se convirtió a WebP y se subió.",
	fr_FR="Impossible de convertir l’image en AVIF ; convertie en WebP à la place et envoyée.",
	de_DE="Bild konnte nicht nach AVIF konvertiert werden; stattdessen nach WebP konvertiert und hochgeladen.",
	nl_NL="Afbeelding kon niet naar AVIF worden geconverteerd; in plaats daarvan naar WebP geconverteerd en geüpload.",
	ru_RU="Не удалось преобразовать изображение в AVIF; вместо этого преобразовано в WebP и загружено.",
	zh_CN="无法将图片转换为 AVIF；已改为转换为 WebP 并上传。",
	ja="画像を AVIF に変換できませんでした。代わりに WebP に変換してアップロードしました。",
)
add(
	"Image converted to WebP successfully and uploaded.",
	es_ES="Imagen convertida a WebP correctamente y subida.",
	fr_FR="Image convertie en WebP avec succès et envoyée.",
	de_DE="Bild erfolgreich nach WebP konvertiert und hochgeladen.",
	nl_NL="Afbeelding succesvol naar WebP geconverteerd en geüpload.",
	ru_RU="Изображение успешно преобразовано в WebP и загружено.",
	zh_CN="图片已成功转换为 WebP 并上传。",
	ja="画像を WebP に変換してアップロードしました。",
)
add(
	"Image couldn't be converted to the selected format; original uploaded.",
	es_ES="No se pudo convertir la imagen al formato seleccionado; se subió el original.",
	fr_FR="Impossible de convertir l’image au format sélectionné ; original envoyé.",
	de_DE="Bild konnte nicht in das gewählte Format konvertiert werden; Original hochgeladen.",
	nl_NL="Afbeelding kon niet naar het geselecteerde formaat worden geconverteerd; origineel geüpload.",
	ru_RU="Не удалось преобразовать изображение в выбранный формат; загружен оригинал.",
	zh_CN="无法将图片转换为所选格式；已上传原图。",
	ja="選択した形式へ変換できませんでした。元のファイルをアップロードしました。",
)
add(
	"JPEG fallback companion created.",
	es_ES="Compañero JPEG de respaldo creado.",
	fr_FR="Compagnon JPEG de repli créé.",
	de_DE="JPEG-Fallback-Begleiter erstellt.",
	nl_NL="JPEG-fallback-companion gemaakt.",
	ru_RU="Создан запасной JPEG-компаньон.",
	zh_CN="已创建 JPEG 回退配套。",
	ja="JPEG フォールバックコンパニオンを作成しました。",
)
add(
	"Dismiss notification",
	es_ES="Descartar notificación",
	fr_FR="Fermer la notification",
	de_DE="Benachrichtigung schließen",
	nl_NL="Melding sluiten",
	ru_RU="Закрыть уведомление",
	zh_CN="关闭通知",
	ja="通知を閉じる",
)
add(
	"Invalid attachment.",
	es_ES="Adjunto no válido.",
	fr_FR="Média non valide.",
	de_DE="Ungültiger Anhang.",
	nl_NL="Ongeldige bijlage.",
	ru_RU="Недопустимое вложение.",
	zh_CN="无效的附件。",
	ja="無効な添付ファイルです。",
)
add(
	"You cannot edit this attachment.",
	es_ES="No puedes editar este adjunto.",
	fr_FR="Vous ne pouvez pas modifier ce média.",
	de_DE="Sie können diesen Anhang nicht bearbeiten.",
	nl_NL="Je kunt deze bijlage niet bewerken.",
	ru_RU="Вы не можете изменить это вложение.",
	zh_CN="你无法编辑此附件。",
	ja="この添付ファイルを編集する権限がありません。",
)
add(
	"You cannot upload files.",
	es_ES="No puedes subir archivos.",
	fr_FR="Vous ne pouvez pas envoyer de fichiers.",
	de_DE="Sie können keine Dateien hochladen.",
	nl_NL="Je kunt geen bestanden uploaden.",
	ru_RU="Вы не можете загружать файлы.",
	zh_CN="你无法上传文件。",
	ja="ファイルをアップロードする権限がありません。",
)
add(
	"Not an image.",
	es_ES="No es una imagen.",
	fr_FR="Ce n’est pas une image.",
	de_DE="Kein Bild.",
	nl_NL="Geen afbeelding.",
	ru_RU="Это не изображение.",
	zh_CN="不是图片。",
	ja="画像ではありません。",
)
add(
	"GIF and SVG files are left unchanged.",
	es_ES="Los archivos GIF y SVG se dejan sin cambios.",
	fr_FR="Les fichiers GIF et SVG sont laissés inchangés.",
	de_DE="GIF- und SVG-Dateien bleiben unverändert.",
	nl_NL="GIF- en SVG-bestanden blijven ongewijzigd.",
	ru_RU="Файлы GIF и SVG не изменяются.",
	zh_CN="GIF 和 SVG 文件保持不变。",
	ja="GIF と SVG ファイルはそのまま残します。",
)
add(
	"File is missing or unreadable.",
	es_ES="El archivo falta o no se puede leer.",
	fr_FR="Le fichier est manquant ou illisible.",
	de_DE="Datei fehlt oder ist nicht lesbar.",
	nl_NL="Bestand ontbreekt of is onleesbaar.",
	ru_RU="Файл отсутствует или не читается.",
	zh_CN="文件缺失或不可读。",
	ja="ファイルが見つからないか、読み取れません。",
)
add(
	"Could not read file size.",
	es_ES="No se pudo leer el tamaño del archivo.",
	fr_FR="Impossible de lire la taille du fichier.",
	de_DE="Dateigröße konnte nicht gelesen werden.",
	nl_NL="Kon de bestandsgrootte niet lezen.",
	ru_RU="Не удалось прочитать размер файла.",
	zh_CN="无法读取文件大小。",
	ja="ファイルサイズを読み取れませんでした。",
)
add(
	"Encoding failed (format may be unsupported on this host).",
	es_ES="La codificación falló (el formato puede no ser compatible en este servidor).",
	fr_FR="Échec de l’encodage (le format peut être non pris en charge sur cet hébergeur).",
	de_DE="Kodierung fehlgeschlagen (Format auf diesem Host möglicherweise nicht unterstützt).",
	nl_NL="Coderen mislukt (formaat wordt mogelijk niet ondersteund op deze host).",
	ru_RU="Ошибка кодирования (формат может не поддерживаться на этом хосте).",
	zh_CN="编码失败（此主机可能不支持该格式）。",
	ja="エンコードに失敗しました（このホストでは形式が非対応の可能性があります）。",
)
add(
	"Already small enough; left unchanged.",
	es_ES="Ya es suficientemente pequeño; se dejó sin cambios.",
	fr_FR="Déjà assez petit ; laissé inchangé.",
	de_DE="Bereits klein genug; unverändert gelassen.",
	nl_NL="Al klein genoeg; ongewijzigd gelaten.",
	ru_RU="Уже достаточно мало; без изменений.",
	zh_CN="已经足够小；未作更改。",
	ja="十分に小さいため、変更しませんでした。",
)
add(
	"Could not regenerate image sizes.",
	es_ES="No se pudieron regenerar los tamaños de imagen.",
	fr_FR="Impossible de régénérer les tailles d’image.",
	de_DE="Bildgrößen konnten nicht neu erzeugt werden.",
	nl_NL="Kon afbeeldingsformaten niet opnieuw genereren.",
	ru_RU="Не удалось пересоздать размеры изображения.",
	zh_CN="无法重新生成图片尺寸。",
	ja="画像サイズを再生成できませんでした。",
)


def parse_pot(path: Path) -> list[tuple[str, str, list[str]]]:
	"""Return list of (msgid, flags, comments)."""
	text = path.read_text(encoding="utf-8")
	blocks = re.split(r"\n\n+", text)
	out: list[tuple[str, str, list[str]]] = []
	for block in blocks:
		if "msgid " not in block:
			continue
		comments = []
		flags = ""
		msgid_parts: list[str] = []
		mode = None
		for line in block.splitlines():
			if line.startswith("#,"):
				flags = line[2:].strip()
			elif line.startswith("#"):
				comments.append(line)
			elif line.startswith("msgid "):
				mode = "id"
				msgid_parts = [line[6:]]
			elif line.startswith("msgstr"):
				mode = "str"
			elif mode == "id" and line.startswith('"'):
				msgid_parts.append(line)
		if not msgid_parts:
			continue
		raw = "".join(
			p[1:-1] if p.startswith('"') and p.endswith('"') else p for p in msgid_parts
		)
		msgid = (
			raw.replace("\\\\", "\x00")
			.replace("\\n", "\n")
			.replace("\\t", "\t")
			.replace('\\"', '"')
			.replace("\x00", "\\")
		)
		if msgid == "":
			continue
		out.append((msgid, flags, comments))
	return out


def po_escape(s: str) -> str:
	return (
		s.replace("\\", "\\\\")
		.replace('"', '\\"')
		.replace("\n", "\\n")
		.replace("\t", "\\t")
	)


def format_msgid(msgid: str) -> str:
	if "\n" in msgid:
		lines = msgid.split("\n")
		parts = ['msgid ""']
		for i, line in enumerate(lines):
			suffix = "\\n" if i < len(lines) - 1 else ""
			parts.append(f'"{po_escape(line)}{suffix}"')
		return "\n".join(parts)
	if len(msgid) > 70:
		# wrap roughly like xgettext for long singles — keep one line for simplicity
		return f'msgid "{po_escape(msgid)}"'
	return f'msgid "{po_escape(msgid)}"'


def format_msgstr(msgstr: str) -> str:
	if "\n" in msgstr:
		lines = msgstr.split("\n")
		parts = ['msgstr ""']
		for i, line in enumerate(lines):
			suffix = "\\n" if i < len(lines) - 1 else ""
			parts.append(f'"{po_escape(line)}{suffix}"')
		return "\n".join(parts)
	return f'msgstr "{po_escape(msgstr)}"'


LOCALES = {
	"es_ES": "Spanish",
	"fr_FR": "French",
	"de_DE": "German",
	"nl_NL": "Dutch",
	"ru_RU": "Russian",
	"zh_CN": "Chinese (Simplified)",
	"ja": "Japanese",
}

# WordPress uses `ja`; some hosts set `ja_JP` — ship an alias of the same catalog.
LOCALE_ALIASES = {
	"ja_JP": "ja",
}


def main() -> None:
	entries = parse_pot(POT)
	missing: dict[str, list[str]] = {loc: [] for loc in LOCALES}

	for locale, lang_name in LOCALES.items():
		# Translation lookup key: ja_JP alias shares ja strings.
		lookup = locale
		parts = [
			"# Copyright (C) 2026 Billy Wilcosky",
			"# This file is distributed under the same license as the Menagerie package.",
			f"# Menagerie {lang_name} translation.",
			"#",
			'msgid ""',
			'msgstr ""',
			'"Project-Id-Version: Menagerie 2.0.0\\n"',
			'"Report-Msgid-Bugs-To: https://wilcosky.com\\n"',
			'"POT-Creation-Date: 2026-11-07\\n"',
			'"PO-Revision-Date: 2026-11-07 12:00-0500\\n"',
			'"Last-Translator: Menagerie\\n"',
			f'"Language-Team: {lang_name}\\n"',
			f'"Language: {locale}\\n"',
			'"MIME-Version: 1.0\\n"',
			'"Content-Type: text/plain; charset=UTF-8\\n"',
			'"Content-Transfer-Encoding: 8bit\\n"',
			'"X-Domain: menagerie\\n"',
			"",
		]
		for msgid, flags, comments in entries:
			trans = T.get(msgid, {}).get(lookup)
			if not trans:
				missing[locale].append(msgid)
				trans = msgid  # fallback should not happen
			for c in comments:
				parts.append(c)
			if flags:
				parts.append(f"#, {flags}")
			parts.append(format_msgid(msgid))
			parts.append(format_msgstr(trans))
			parts.append("")

		po_path = LANG_DIR / f"menagerie-{locale}.po"
		po_path.write_text("\n".join(parts) + "\n", encoding="utf-8")
		mo_path = LANG_DIR / f"menagerie-{locale}.mo"
		subprocess.run(["msgfmt", "-o", str(mo_path), str(po_path)], check=True)
		print(f"Wrote {po_path.name} + {mo_path.name} ({len(entries)} strings)")

	for alias, source in LOCALE_ALIASES.items():
		src_po = LANG_DIR / f"menagerie-{source}.po"
		src_mo = LANG_DIR / f"menagerie-{source}.mo"
		alias_po = LANG_DIR / f"menagerie-{alias}.po"
		alias_mo = LANG_DIR / f"menagerie-{alias}.mo"
		text = src_po.read_text(encoding="utf-8")
		text = text.replace(f'"Language: {source}\\n"', f'"Language: {alias}\\n"', 1)
		alias_po.write_text(text, encoding="utf-8")
		subprocess.run(["msgfmt", "-o", str(alias_mo), str(alias_po)], check=True)
		print(f"Wrote alias {alias_po.name} + {alias_mo.name} (from {source})")

	any_missing = False
	for locale, msgs in missing.items():
		if msgs:
			any_missing = True
			print(f"MISSING {locale}: {len(msgs)}")
			for m in msgs:
				print("  -", m[:80])
	if any_missing:
		raise SystemExit(1)
	print("All locales 100% translated.")


if __name__ == "__main__":
	main()
