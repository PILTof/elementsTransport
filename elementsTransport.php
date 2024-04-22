<?
use \CENSORSHIP\comment;
use \CENSORSHIP\like;
use \CENSORSHIP\log;
use \CENSORSHIP\nominations;
use CDatabase;
use \CENSORSHIP\tags;
use \CENSORSHIP\userquery;
use \CENSORSHIP\work;


define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define('BX_NO_ACCELERATOR_RESET', true);
define('CHK_EVENT', true);
define('BX_WITH_ON_AFTER_EPILOG', true);


require ($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

@set_time_limit(0);
@ignore_user_abort(true);
define("BX_CRONTAB_SUPPORT", true);
define("BX_CRONTAB", true);

\Bitrix\Main\Loader::includeModule('#CENSORSHIP');
\Bitrix\Main\Loader::includeModule('iblock');
?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<?


// Добавление пользователя, цель получить добавить пользователя и\или получить его ID
function setUser($arUser): int
{
    if (empty($arUser))
        return false;
    $tag = new tags;
    $tags = [];
    // У пользователя есть тэги, размешаются в отдельном ИБ
    foreach ($arUser['tags'] as $k => $itag) {
        $tID = $tag->getOrCreate($itag['name']['ru'], $itag['type'], tags::SECTION_ID_PROFESSION_IN_MUS);
        if ($tID)
            $tags[] = $tID;
    }

    $user = new userquery;
    // Создание или получение ID пользователя
    $user_id = $user->getOrCreate([
        'name' => $arUser['name'],
        'email' => $arUser['email'],
        'deti' => $arUser['deti'],
        'city' => $arUser['city'],
        'notes' => $arUser['about'],
        'TAGS' => $tags,
        'avatar' => preg_replace('/.*\/storage/', '/#CENSORSHIP/public', $arUser['media'][0]['original_url'])
    ]);
    return $user_id;
}



function connect(int|string $id): array
{
    // Ответ по API возвращается с типом Json
    $url = "#CENSORSHIP?last=" . $id;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HEADER, false);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}


if (isset($_REQUEST['id']))
    $needed_ids = array_filter($_REQUEST['id'], fn($q) => !empty ($q));


if ($_REQUEST['run'] == "Y" && $_REQUEST['id']) {
    // Простой класс лога
    $log = new log($_SERVER['DOCUMENT_ROOT'] . '/tests/notes', 'import.special.works.log');

    try {
        foreach ($needed_ids as $key => $id) {

            if ($data = connect($id)) {
                foreach ($data['res'] as $key => $item) {
                    // START Проверка на существование элемента ИБ
                    $dctFilter = [
                        'IBLOCK_ID' => work::IBLOCK_ID_WORK,
                        'PROPERTY_LARAVEL_ID' => $item['id']
                    ];
                    $edb = \CIBlockElement::GetList([], $dctFilter, false, false, [
                        'IBLOCK_ID',
                        'ID',
                    ]);
                    if (($existed = $edb->SelectedRowsCount()) > 0) {
                        $log->write("Работа [ {$item['id']} ] уже существует  - [ {$existed['ID']} ]");
                        $log->save();
                        continue;
                    }
                    // END Проверка на существование элемента ИБ

                    // Добавление элемента
                    $date_c = new \DateTime($item['created_at']);
                    $date = $item['premiere_date'] ? new \DateTime($item['premiere_date']) : false;
                    $rsElement = new \CIBlockElement;
                    $dctFields = array(
                        'ACTIVE' => $item['published'] ? "Y" : "N",
                        'IBLOCK_ID' => work::IBLOCK_ID_WORK,
                        'NAME' => $item['title'],
                        'DETAIL_TEXT' => $item['body'],
                        'DATE_CREATE' => $date_c->format('d.m.Y h:m:s'),
                        'IBLOCK_SECTION_ID' => work::SECTION_ID_DETI,
                        'PROPERTY_VALUES' => array(
                            'LARAVEL_ID' => $item['id'],
                            'IS_CHILD' => $item['child'] ? 1 : null,
                            'COAUTHOR_SEARCH' => $item['coauthor_search'],
                            // comments
                            'COMMENTS_BLOCKED' => $item['comments_blocked'],
                            // подборки compilations,
                            // likes,
                            'VIEWS_COUNT' => $item['views_count'],
                            "LIKES_BLOCKED" => $item['likes_blocked'] ? 1 : null,
                            'MUSIC_AUTHOR' => $item['music_author'],
                            'MUSIC_PERFORMER' => $item['music_performer'],
                            'MUSIC_TEXT' => $item['music_text'],
                            'NOMINATION_TAGS' => $nomalias[$item['nominant']],
                            "PERFORMER_STATUS" => $item['performer_status'],
                            "PREMIERE_DATE" => $date ? $date->toString() : false,
                            'VIDEO_FRAME' => $item['video_frame'],
                            'VIDEO_URL' => $item['video_url'],
                            'RANGING' => $item['ranging']
                        )
                    );

                    // У элемента есть "Тэги", которые находятся в отдельном ИБ
                    if ($item['tags']) {

                        // Класс тэгов специализирован для работы с этим ИБ
                        $tag = new tags;
                        $tags = [];
                        foreach ($item['tags'] as $k => $arTag) {
                            $section = null;
                            switch ($arTag['type']) {
                                case 'user':
                                    $section = tags::SECTION_ID_PROFESSION_IN_MUS;
                                    break;
                                case 'article':
                                    $section = tags::SECTION_ID_ARTTICLE;
                                    break;
                                case 'work-genre':
                                    $section = tags::SECTION_ID_GENRES;
                                    break;
                                case 'work-theme':
                                    $section = tags::SECTION_ID_THEMES;
                                    break;
                                case 'work':
                                    $section = tags::SECTION_ID_PROFESSION_IN_MUS;
                                case 'work-type':
                                    $types = work::getTypes();
                                    $type = array_filter($types, fn($q) => $q['VALUE'] == $arTag['name']['ru']);
                                    if (count($type) > 0)
                                        $dctFields['PROPERTY_VALUES']['WORK_TYPE'] = array_key_first($type);

                                    break;
                                case 'work-additional':
                                    break;
                                case 'coauthor-role':
                                    $section = tags::SECTION_ID_COAUTHOR_ROLES;
                                    break;
                                case 'author-role':
                                    $section = tags::SECTION_ID_ROLES;
                                    break;
                                default:
                                    break;
                            }
                            if ($section) {
                                $tID = $tag->getOrCreate($arTag['name']['ru'], $arTag['type'], $section);
                                if ($tID)
                                    $tags[] = $tID;
                            }
                        }
                        $dctFields['PROPERTY_VALUES']['TAGS'] = $tags;
                    }
                    if ($item['user']) {
                        $dctFields['PROPERTY_VALUES']['USER_ID'] = setUser($item['user']);
                    }
                    $mixedFiles = [];
                    // В ответе API приходят медиа файлы хранящиеся на монтированном диске сервера
                    foreach ($item['media'] as $k => $media) {
                        $src = preg_replace('/.*\/storage/', '/#CENSORSHIP/public', $media['original_url']);
                        $file = \CFile::MakeFileArray($src);
                        $mixedFiles[] = $file;
                        switch ($media['collection_name']) {
                            case "workPreview":
                                if ($_REQUEST['ignore-workPreview'] !== "on") {
                                    $dctFields['DETAIL_PICTURE'] = $file;
                                }
                                break;
                            case "workAudio":
                                if ($_REQUEST['ignore-workAudio'] !== "on") {
                                    $dctFields['PROPERTY_VALUES']['FILE_AUDIO'] = $file;
                                }
                                break;
                            case "workGallery":
                                if ($_REQUEST['ignore-workGallery'] !== "on") {
                                    $dctFields['PROPERTY_VALUES']['FILE_GALLERY'][] = $file;
                                }
                                break;
                            default:
                                # code...
                                break;
                        }
                    }

                    if (!$id = $rsElement->Add($dctFields)) {
                        echo 'Error:' . $rsElement->LAST_ERROR;
                        $log->write("Ошибка добавления - [ {$item['id']} ]" . $rsElement->LAST_ERROR . PHP_EOL . 'Файл --' . PHP_EOL . 'Конечный массив: ' . print_r($mixedFiles, true));
                    } else {

                        // У работы есть лайки, комменты, сабкомменты и лайки сабкомментов
                        if ($item['likes']) {
                            foreach ($item['likes'] as $key => $arLike) {
                                if (empty($arLike['user_id']))
                                    continue;
                                $work_like = like::createLike('work', $id, setUser($arLike['user']), $arLike['user_ip']);
                            }
                        }
                        if ($item['comments']) {
                            foreach ($item['comments'] as $arComment) {
                                $comment = new comment;

                                $user = new userquery;
                                $date = new \DateTime($arSubComment['created_at']);

                                $cm_id = $comment->createComment([
                                    'body' => $arComment['body'],
                                    'date' => $date->format('d.m.Y h:m:s'),
                                    'user' => ['id' => setUser($arComment['user']), 'ip' => $arComment['user_ip']]
                                ], $id, 'work');
                                foreach ($arComment['likes'] as $key => $arLike) {
                                    if (empty($arLike['user_id']))
                                        continue;
                                    $like_id = like::createLike('comment', $cm_id, setUser($arLike['user']), $arLike['user_ip']);
                                }
                                foreach ($arComment['comments'] as $key => $arSubComment) {
                                    $sub_comment = new comment;
                                    $sub_comment_user = new userquery;
                                    $sub_date = new \DateTime($arSubComment['created_at']);
                                    $sub_cm_id = $sub_comment->createComment([
                                        'body' => $arSubComment['body'],
                                        'date' => $sub_date->format('d.m.Y h:m:s'),
                                        'user' => ['id' => setUser($arSubComment['user']), 'ip' => $arSubComment['user_ip']]
                                    ], $cm_id, 'comment');
                                    foreach ($arSubComment['likes'] as $key => $arSubLike) {
                                        if (empty($arSubLike['user_id']))
                                            continue;
                                        $sub_like_id = like::createLike('comment', $sub_cm_id, setUser($arSubLike['user']), $arSubLike['user_ip']);
                                    }
                                }
                            }
                        }
                    }


                }
            } else {
                $log->write("Работа не найдена - {$id}");
            }
            $imported[] = $id;
        }
    } catch (\Throwable $th) {
        $log->write($th->getMessage() . '    -' . var_dump($needed_ids));
    }
    $finish = true;
    $log->save();

}
if ($finish) {
    LocalRedirect('#CENSORSHIP/import.special.works.php');
}


// Визуальная часть
?>
<div>
    <p style="color: gray;">
        Скрипт с этой страницы импортирует элементы (включая комментарии и лайки) и в случае отсуствия пользователя -
        самого пользователя
        <br>
        <br>
        *Скрипт не дублирует записи
        <br>
        *Нужно указывать ID эелмента с #CENSORSHIP
        <br>
        *Страницу можно закрыть, но лучше дождаться выполнения
        <br>
        Лог можно читать - <a href="#CENSORSHIP\import.special.works.log">тут</a>
    </p>
</div>
<div style="display:flex;justify-content:center;">
    <form id="import-works" style="display: flex; flex-direction:column;row-gap:20px;background-color: white;
  padding: 20px;
  border-radius: 10px;
  border: 2px gray solid;" action="?run=Y">
        <label for="id">ID</label>
        <input type="hidden" name="run" value="Y">
        <div style="display:flex;flex-direction:column;row-gap:10px;">
            <div style="display:flex; max-width: 200px;justify-content: space-between;">
                <label for="ignore-workPreview">Игнорировать файл превью</label>
                <input type="checkbox" name="ignore-workPreview">
            </div>
            <div style="display:flex; max-width: 200px;justify-content: space-between;">
                <label for="ignore-workAudio">Игнорировать файл аудио</label>
                <input type="checkbox" name="ignore-workAudio">
            </div>
            <div style="display:flex; max-width: 200px;justify-content: space-between;">
                <label for="ignore-workGallery">Игнорировать все файлы галлереи</label>
                <input type="checkbox" name="ignore-workGallery">
            </div>

        </div>
        <div id="input-container" style="display:flex; flex-direction:column;row-gap:20px;">
            <input style="max-width:200px" name="id[]" type="number">
            <input style="max-width:200px" name="id[]" type="number">
            <input style="max-width:200px" name="id[]" type="number">
        </div>
        <div style="display:flex;column-gap: 40px;">
            <div style="cursor:pointer;background-color: #e5e5e5;border: solid 1px black;border-radius:2px;max-width:80px;"
                onclick="return addInput();">Добавить поле</div>
            <div style="cursor:pointer;background-color: #e5e5e5;border: solid 1px black;border-radius:2px;max-width:80px;"
                onclick="return clearInp();">Сбросить</div>

        </div>
        <button style="max-width: 200px;" type="submit">Импортировать</button>
    </form>
</div>
<?


?>
<script>
    function clearInp() {
        window.location.href = '#CENSORSHIP/import.special.works.php';
    }
    function addInput() {
        let last = $('#input-container').children().last();
        let clone = last.clone();
        $('#input-container').append(clone)
    }
</script>