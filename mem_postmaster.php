<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'mem_postmaster';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '1.0.20';
$plugin['author'] = 'Ben Bruce/Michael Manfre';
$plugin['author_uri'] = '';
$plugin['description'] = 'Simple email-on-post/newsletter manager for Textpattern';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '5';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '0';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

/** Uncomment me, if you need a textpack
$plugin['textpack'] = <<< EOT
#@admin
#@language en-gb
abc_sample_string => Sample String
abc_one_more => One more
#@language de-de
abc_sample_string => Beispieltext
abc_one_more => Noch einer
EOT;
**/
// End of textpack

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/*
Postmaster
by Ben Bruce
Help documentation: http://www.benbruce.com/postmaster
Support forum thread: http://forum.textpattern.com/viewtopic.php?id=19510
*/
global $event, $step;

if( !defined('bab_pm_prefix') )
    define( 'bab_pm_prefix' , 'bab_pm' );

if (!defined('BAB_CUSTOM_FIELD_COUNT'))
    define('BAB_CUSTOM_FIELD_COUNT', 20);

if (class_exists('\Textpattern\Tag\Registry')) {
    Txp::get('\Textpattern\Tag\Registry')
        ->register('bab_pm_channels')
        ->register('bab_pm_channel')
        ->register('bab_pm_data')
        ->register('bab_pm_mime')
        ->register('bab_pm_unsubscribeLink');
}

register_callback('bab_pm_comconnect_submit','comconnect.submit'); // plugs into com_connect, public-side

if (txpinterface === 'admin') {
    add_privs('postmaster', '1,2');
    register_tab('extensions', 'postmaster', 'Postmaster');
    register_callback('bab_postmaster', 'postmaster');
    register_callback("bab_pm_eop", 'postmaster' , 'bab_pm_eop');
//    register_callback("bab_pm_eop", 'article' , 'save');
//    register_callback("bab_pm_eop", 'article' , 'publish');
//  register_callback("bab_pm_writetab", 'article' , ''); // default is "only while editing"
    register_callback("bab_pm_writetab", 'article' , 'edit');
//    register_callback("bab_pm_writetab", 'article' , 'save');
//    register_callback("bab_pm_writetab", 'article' , 'publish');
}

// ----------------------------------------------------------------------------
// "Content > Write" panel
function bab_pm_writetab($evt, $stp)
{
    global $app_mode;

    if ($app_mode === 'async') {
        return;
    }

    $bab_pm_PrefsTable = safe_pfx('bab_pm_list_prefs');
    $options = '';

    // get available lists to create dropdown menu

    $bab_pm_lists = safe_query("select * from $bab_pm_PrefsTable");

    while ($row = @mysqli_fetch_row($bab_pm_lists)) {
        $options .= "<option>$row[1]</option>";
    }

    $selection = '<select id="listToEmail" name="listToEmail">' . $options . '</select>';
    $line = <<<EOL
<script>
function mem_toggleExcerptTinyMCE() {
    try {
        hak_toggleEditor('Excerpt');
    } catch (err) { }
}

$(function() {
    $('.mem_publish').on('click', function(ev) {
        var theForm = $('#article_form');
        var theList = theForm.find('#listToEmail').val();
        var theSender = theForm.find('#postmaster-sendFrom').val();
        var theArticle = parseInt(theForm.find('input[name=ID]').val(), 10);
        var theButton = $(this).val();
        var bab_pm_radio = (theButton === 'send-test') ? 2 : 1;

        var path = '?event=postmaster&step=bab_pm_eop&bab_pm_radio=' + bab_pm_radio + '&listToEmail=' + encodeURIComponent(theList) + '&artID=' + theArticle;

        if (theSender != '') {
            path += '&sendFrom=' + encodeURIComponent(theSender);
        }
console.log(path);
        window.location.href = path;
    });
});
</script>
<h3 class="plain"><a onclick="toggleDisplay('bab_pm'); mem_toggleExcerptTinyMCE(); return false;" href="#bab_pm">Postmaster</a></h3>

<div id="bab_pm" class="toggle" style="display: none;">
    <fieldset id="email-to-subscribers">
        <legend>Email to subscribers?</legend>
        <div style="margin-top:5px"><label for="listToEmail" class="listToEmail">Select list:</label> $selection</div>
        <div style="margin-top:5px">
            <label for="postmaster-sendFrom" class="sendFrom">From email:</label>
            <input id="postmaster-sendFrom" type="text" name="sendFrom" value="" />
        </div>
        <div style="margin-top:5px">
            <button type="button" name="save" class="mem_publish publish" value="send-list">Send to List</button>
            &nbsp;&nbsp;
            <button type="button" name="save" class="mem_publish publish" value="send-test">Send to Test</button>
        </div>
    </fieldset>
</div>
EOL;

    // for 4.0.4
    if (is_callable('dom_attach')) {
        echo dom_attach('supporting_content', $line, $line, 'div');
        return;
    }

    // for 4.0.3 and earlier
    $line = addcslashes($line, "\r\n\"\'");

    echo $js = <<<eof
<script language="javascript" type="text/javascript">
<!--

var table = document.getElementById('articleside');
var p = document.createElement('div')
p.innerHTML = '$line'
table.appendChild(p)

// -->
</script>
<noscript>
<p>$line</p>
</noscript>
eof;

} // end bab_pm_writetab

// ----------------------------------------------------------------------------
// "Admin > Postmaster" tab

function bab_postmaster($evt, $stp='')
{
    global $bab_pm_PrefsTable, $bab_pm_SubscribersTable;

    if ($evt == 'postmaster' && $stp == 'export') {
        bab_pm_export();
        return;
    }

    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");

    // session_start();
    pagetop('Postmaster','');

    // define the users table names (with prefix)
    $bab_pm_PrefsTable = safe_pfx('bab_pm_list_prefs');
    $bab_pm_SubscribersTable = safe_pfx('bab_pm_subscribers');


    // set up script for hiding add sections
    echo $jshas = <<<jshas
<script type="text/javascript">
$(document).ready(function(){
    $("a.show").click(function() {
        console.log('hi');
        $(this).closest('fieldset').find('.stuff').toggle();
    });
});
</script>
jshas;

    // check tables. if not exist, create tables
    $check_prefsTable = @getThings('describe `'.PFX.'bab_pm_list_prefs`');
    $check_subscribersTable = @getThings('describe `'.PFX.'bab_pm_subscribers`');

    if (!$check_prefsTable or !$check_subscribersTable) {
        bab_pm_createTables();
    }

    $sql = "SHOW COLUMNS FROM {$bab_pm_SubscribersTable} LIKE '%name%'";

    $rs = safe_query($sql);

    if (numRows($rs) < 2) {
        //upgrade the db
        bab_pm_upgrade_db();
    }

    // define postmaster styles

    bab_pm_create_subscribers_list();

    bab_pm_styles();

    bab_pm_poweredit();

    // masthead / navigation

    // fix this hack
    $step = gps('step');

    if (!$step) {
        $step = 'subscribers';
    }
    //assign all down state
    $td_subscribers = $td_lists = $td_importexport = $td_formsend = $td_prefs = '<td class="navlink">';

    $active_tab_var = 'td_' . $step;
    $$active_tab_var = '<td class="navlink-active">';

    $pm_nav = <<<pm_nav
<table id="pagetop" cellpadding="0" cellspacing="0" style="padding-bottom:10px;margin-top:-20px;">
    <tr id="nav-secondary"><td align="center" class="tabs" colspan="2">
        <td>
            <table id="bab_pm_nav" cellpadding="0" cellspacing="0" align="center" colspan="2" style="margin-bottom:30px;" >
                <tr>
                $td_subscribers<a href="?event=postmaster&step=subscribers"  class="plain">Subscribers</a></td>
                $td_lists<a href="?event=postmaster&step=lists"  class="plain">Lists</a></td>
                $td_importexport<a href="?event=postmaster&step=importexport"  class="plain">Import/Export</a></td>
                $td_formsend<a href="?event=postmaster&step=formsend"  class="plain">Direct Send</a></td>
                $td_prefs<a href="?event=postmaster&step=prefs"  class="plain">Preferences</a></td>
                </tr>
            </table>
        </td>
    </tr>
</table>
pm_nav;

    echo '<div id="bab_pm_master">';
    echo '<div id="bab_pm_nav">' . $pm_nav . '</div>';
    echo '<div id="bab_pm_content">';
    bab_pm_ifs(); // deal with the "ifs" (if delete button pushed, etc)

    include_once txpath.'/publish.php'; // testing page_url

    $handler  = "bab_pm_$step";

    if (!function_exists($handler)) {
        $handler = "bab_pm_subscribers";
    }

    $handler();

    echo '</div>'; // end bab_pm_content
    echo '</div>'; // end master_bab_pm
}

// ----------------------------------------------------------------------------
// "Admin > Postmaster > Subscribers" tab

function bab_pm_makeform()
{
    global $prefs;

    $bab_hidden_input = '';
    $event = gps('event');
    $step = gps('step');

    if (!$event) {
        $event = 'postmaster';
    }

    if (!$step) {
        $step = 'subscribers';
    }

    if ($step == 'subscribers') {
        $bab_columns = array(
            'subscriberFirstName',
            'subscriberLastName',
            'subscriberEmail',
            'subscriberLists',
        );

        for ($i = 1; $i <= BAB_CUSTOM_FIELD_COUNT; $i++) {
            $bab_columns[] = "subscriberCustom{$i}";
        }

        $bab_submit_value = 'Add Subscriber';
        $bab_prefix = 'new';
        $subscriberToEdit = gps('subscriber');

        if ($subscriberToEdit) {
            $bab_hidden_input = '<input type="hidden" name="editSubscriberId" value="' . doSpecial($subscriberToEdit) . '">';
            $bab_prefix = 'edit';
            $bab_submit_value = 'Update Subscriber Information';

            $row = safe_row('*', 'bab_pm_subscribers', "subscriberID=" . doSlash($subscriberToEdit));
            $subscriber_lists = safe_rows('*', 'bab_pm_subscribers_list', "subscriber_id = " . doSlash($row['subscriberID']));
            $fname = doSpecial($row['subscriberFirstName']);
            $lname = doSpecial($row['subscriberLastName']);
            echo "<fieldset id=bab_pm_edit><legend><span class=bab_pm_underhed>Editing Subscriber: $fname $lname</span></legend>";
        } else {
            $subscriber_lists = array();
        }

        $lists = safe_rows('*', 'bab_pm_list_prefs', '1=1 order by listName');
    }

    if ($step == 'lists') {
        $bab_columns = array('listName', 'listAdminEmail','listDescription','listUnsubscribeUrl','listEmailForm','listSubjectLine');
        $bab_submit_value = 'Add List';
        $bab_prefix = 'new';

        if ($listToEdit = gps('list')) {
            $bab_hidden_input = '<input type="hidden" name="editListID" value="' . $listToEdit . '">';
            $bab_prefix = 'edit';
            $bab_submit_value = 'Update List Information';
            $bab_prefix = 'edit';

            $row = safe_row('*', 'bab_pm_list_prefs', "listID=" . doSlash($listToEdit));
            echo "<fieldset id=bab_pm_edit><legend>Editing List: $row[listName]</legend>";
        }

        $form_prefix = $prefs[_bab_prefix_key('form_select_prefix')];

        $forms = safe_column('name', 'txp_form',"name LIKE '". doSlash($form_prefix) . "%'");
        $form_select = selectInput($bab_prefix.ucfirst('listEmailForm'), $forms, @$row['listEmailForm']);
        // replace class
        $form_select = str_replace('class="list"', 'class="bab_pm_input"', $form_select);
    }

    // build form

    echo '<form method="POST" id="subscriber_edit_form">';

    foreach ($bab_columns as $column) {
        echo '<dl class="bab_pm_form_input"><dt>'.bab_pm_preferences($column).'</dt><dd>';
        $bab_input_name = $bab_prefix . ucfirst($column);

        switch ($column) {
            case 'listEmailForm':
                echo $form_select;
                break;
            case 'listSubjectLine':
                $checkbox_text = 'Use Article Title for Subject';
            case 'listUnsubscribeUrl':
                if (empty($checkbox_text)) {
                    $checkbox_text = 'Use Default';
                }

                $checked = empty($row[$column]) ? 'checked="checked" ' : '';
                $js = <<<eojs
<script>
$(document).ready(function () {
    $('#{$column}_checkbox').change(function(){
        if ($(this).is(':checked')) {
            $('input[name={$bab_input_name}]').attr('disabled', true).val('');
        }
        else {
            $('input[name={$bab_input_name}]').attr('disabled', false);
        }
    });
});
</script>

eojs;

                echo $js . '<input id="'.$column.'_checkbox" type="checkbox" class="bab_pm_input" ' . $checked . '/>'.$checkbox_text.'</dd><dd>' .
                    '<input type="text" name="' . $bab_input_name . '" value="' . doSpecial(@$row[$column]) . '"' .
                        (!empty($checked) ? ' disabled="disabled"' : '') . ' />' .
                    '</dd>';
                break;
            case 'subscriberLists':
                foreach ($lists as $list) {
                    $checked = '';

                    foreach ($subscriber_lists as $slist) {
                        if ($list['listID'] == $slist['list_id']) {
                            $checked = 'checked="checked" ';
                            break;
                        }
                    }

                    echo '<input type="checkbox" name="'. $bab_input_name .'[]" value="'.$list['listID'].'"' . $checked . '/>'
                        . doSpecial($list['listName']) . "<br>";
                }
                break;
            default:
                echo '<input type="text" name="' . $bab_input_name . '" value="' . doSpecial(@$row[$column]) . '" class="bab_pm_input">';
                break;
        }

        echo '</dd></dl>';
    }

    echo $bab_hidden_input;
    echo '<input type="submit" value="' . doSpecial($bab_submit_value) . '" class="publish">';
    echo '</form>';
}

function bab_pm_formsend()
{
    //test for incoming send request - nevermind...we'll just fire off the mail function
    $bab_pm_PrefsTable = safe_pfx('bab_pm_list_prefs');
    $bab_pm_SubscribersTable = safe_pfx('bab_pm_subscribers');

    $bab_pm_radio = ps('bab_pm_radio'); // this is whether to mail or not, or test

    if ($bab_pm_radio == 'Send to Test') {
        $bab_pm_radio = 2;
    }

    if ($bab_pm_radio == 'Send to List') {
        $bab_pm_radio = 1;
    }

    if ($bab_pm_radio != 0) { // if we have a request to send, start the ball rolling....
        // email from override
        $sendFrom = gps('sendFrom');

        $listToEmail = (!empty($_REQUEST['listToEmail'])) ? gps('listToEmail') : gps('list');
        // $listToEmail = gps('listToEmail'); // this is the list name
        $subject = gps('subjectLine');
        $form = gps('override_form');

        // ---- scrub the flag column for next time:
        $result = safe_query("UPDATE $bab_pm_SubscribersTable SET flag = NULL");

        //time to fire off initialize
        // bab_pm_initialize_mail();
        $path = "?event=postmaster&step=initialize_mail&radio=$bab_pm_radio&list=$listToEmail&artID=$artID";

        if (!empty($sendFrom)) {
            $path .= "&sendFrom=" . urlencode($sendFrom);
        }

        if (!empty($subject)) {
            $path .= "&subjectLine=" . urlencode($subject);
        }

        if ($_POST['use_override'] && !empty($form)) {
            $path .= "&override_form=$form&use_override=1";
        }

        header("HTTP/1.x 301 Moved Permanently");
        header("Status: 301");
        header("Location: ".$path);
        header("Connection: close");
    }

    $options = '';
    $form_select = '';

    // get available lists to create dropdown menu
    $bab_pm_lists = safe_query("select * from $bab_pm_PrefsTable");

    while ($row = @mysqli_fetch_row($bab_pm_lists)) {
        $options .= "<option>$row[1]</option>";
    }

    $selection = '<select id="listToEmail" name="listToEmail">' . $options . '</select>';

    $form_list = safe_column('name', 'txp_form',"name like 'newsletter-%'");

    if (count($form_list) > 0) {
        foreach ($form_list as $form_item) {
            $form_options[] = "<option>$form_item</option>";
        }

        $form_select = '<select name="override_form">' . join($form_options,"\n") . '</select>';
        $form_select .= checkbox('use_override', '1', '').'Use override?';
    }

    if (isset($form_select) && !empty($form_select)) {
            $form_select = <<<END
                <div style="margin-top:5px">
                    Override form [optional]: $form_select
                </div>
END;
    }
    echo <<<END
<form action="" method="post" accept-charset="utf-8">
    <fieldset id="bab_pm_formsend">
        <legend><span class="bab_pm_underhed">Form-based Send</span></legend>
            <div style="margin-top:5px">
                <label for="listToEmail" class="listToEmail">Select list:</label> $selection
            </div>
            $form_select
            <label for="sendFrom" class="sendFrom">Send From:</label><input type="text" name="sendFrom" value="" id="sendFrom" /><br />
            <label for="subjectLine" class="subjectLine">Subject Line:</label><input type="text" name="subjectLine" value="" id="subjectLine" /><br />

            <p><input type="submit" name="bab_pm_radio" value="Send to Test" class="publish" />
            &nbsp;&nbsp;
            <input type="submit" name="bab_pm_radio" value="Send to List" class="publish" /></p>
    </fieldset>
</form>
END;
}

function bab_pm_subscribers()
{
    $step = gps('step');

    if ($subscriberToEdit= gps('subscriber')) {
        bab_pm_makeform();
    } else {
        // total subscribers
        $lvars = array('page','sort','dir','crit','method');
        extract(gpsa($lvars));

        $total = getCount('bab_pm_subscribers',"1");
        echo '<fieldset id="bab_pm_total-lists"><legend><span class="bab_pm_underhed">Total Subscribers</span></legend><br />' . $total . '</fieldset>';

        // add a subscriber

        echo '<fieldset id="bab_pm_edit"><legend><span class="bab_pm_underhed"><a href="#" class="show">Add a Subscriber</a></span></legend><br /><div class="stuff">';
        bab_pm_makeform();

        echo '</div></fieldset>';

        // subscriber list
        if (empty($method)) {
            $method = gps('search_method');
        }

        if (!$sort) {
            $sort = "subscriberLastName";
        }

        //$dir = $dir == 'desc' ? 'desc' : 'asc';
        if ($crit) {
            $slash_crit = doSlash($crit);
            $critsql = array(
                'name' => "(subscriberLastName rlike '$slash_crit' or subscriberFirstName rlike '$slash_crit')",
                'email'     => "subscriberEmail rlike '$slash_crit'",
                'lists' => "T.subscriberLists rlike '$slash_crit'"
            );

            if (array_key_exists($method, $critsql)) {
                $criteria = $critsql[$method];
            } else {
                if (strncmp($method, 'subscriberCustom', 16) == 0) {
                    $custom_criteria = "(S.{$method} rlike '{$slash_crit}')";
                }
            }
            //$limit = 100;
        }

        if (empty($criteria)) {
            $criteria = '1';
        }

        if (empty($custom_criteria)) {
            $custom_criteria = '1';
        }

        $q = "";

        $list_table = safe_pfx('bab_pm_list_prefs');
        $map_table = safe_pfx('bab_pm_subscribers_list');
        $sub_table = safe_pfx('bab_pm_subscribers');

        $q = <<<EOSQL
SELECT COUNT(*) FROM (
SELECT S.subscriberID, S.subscriberFirstName, S.subscriberLastName, S.subscriberEmail,
GROUP_CONCAT(L.listName ORDER BY L.listName SEPARATOR ', ') as subscriberLists
FROM `$sub_table` as S left join `$map_table` as MAP ON S.subscriberID=MAP.subscriber_id
left join `$list_table` as L ON MAP.list_id=L.listID
WHERE $custom_criteria
GROUP BY S.subscriberID) as T
WHERE $criteria
EOSQL;
        $search_results = $total = getThing($q);


        $limit = bab_pm_preferences('subscribers_per_page');

        if (!$limit) {
            $limit = 20;
        }

        $numPages = ceil($total/$limit);
        $page = (!$page) ? 1 : $page;
        $offset = ($page - 1) * $limit;

        $q = <<<EOSQL
SELECT * FROM (
SELECT S.subscriberID, S.subscriberFirstName, S.subscriberLastName, S.subscriberEmail,
GROUP_CONCAT(L.listName ORDER BY L.listName SEPARATOR ', ') as subscriberLists
FROM `$sub_table` as S left join `$map_table` as MAP ON S.subscriberID=MAP.subscriber_id
left join `$list_table` as L ON MAP.list_id=L.listID
WHERE $custom_criteria
GROUP BY S.subscriberID) as T
WHERE $criteria
ORDER BY $sort
LIMIT $offset, $limit
EOSQL;

        $gesh = startRows($q);

        echo '<fieldset id="bab_pm_list-of-subscribers"><legend><span class="bab_pm_underhed">List of Subscribers</span></legend><br />';
        echo subscriberlist_nav_form($page, $numPages, $sort, $dir, $crit, $method);
        echo subscriberlist_searching_form($crit, $method);

        if ($gesh) {
            echo '<form method="post" name="longform" class="multi_edit_form">',
                startTable('list'),
                '<tr>',
                bab_pm_column_head('First Name', 'subscriberFirstName', 'postmaster', 1, ''),
                bab_pm_column_head('Last Name', 'subscriberLastName', 'postmaster', 1, ''),
                bab_pm_column_head('Email', 'subscriberEmail', 'postmaster', 1, ''),
                bab_pm_column_head('Lists', 'subscriberLists', 'postmaster', 0, ''),
                bab_pm_column_head(NULL),
            '</tr>';

            while ($a = nextRow($gesh)) {
                extract(doSpecial($a));

                $modbox = fInput('checkbox','selected[]',$subscriberID,'','','','','','subscriberid_'. $subscriberID);

                if (empty($subscriberFirstName) && empty($subscriberLastName)) {
                    $subscriberFirstName = '(empty)';
                }

                $editLinkFirst = '&nbsp;<a href="?event=postmaster&step=subscribers&subscriber=' . $subscriberID . ' ">' . $subscriberFirstName . '</a>&nbsp;';
                $editLinkLast = '&nbsp;<a href="?event=postmaster&step=subscribers&subscriber=' . $subscriberID . ' ">' . $subscriberLastName . '</a>&nbsp;';
                echo "<tr>".n,
                    td($editLinkFirst,125),
                    td($editLinkLast,125),
                    td($subscriberEmail,250),
                    td($subscriberLists,125),
                    td($modbox),
                '</tr>'.n;
            }

            $bab_pm_lists = safe_rows('listId, listName', 'bab_pm_list_prefs', '1=1');
            $listOpts = array();

            foreach ($bab_pm_lists as $row) {
                $listOpts[$row['listId']] = $row['listName'];
            }

            $all_lists = selectInput('selected_list_id', $listOpts, '', true);
            $methods = array(
                'add_to_list' => array(
                    'label' => bab_pm_gTxt('add_to_list'),
                    'html'  => $all_lists,
                ),
                'remove_from_list' => array(
                    'label' => bab_pm_gTxt('remove_from_list'),
                    'html'  => $all_lists,
                ),
                'delete' => gTxt('delete'),
            );

            echo "<tr>".n,
                tda(
                    multi_edit($methods, 'postmaster', 'postmaster_multi_edit', $page, $sort, $dir, $crit, $method)
                    , ' colspan="5" style="text-align: right; border: none;"'
                ),
            '</tr>'.n;
            echo "</table></form>";
            unset($sort);
        }

        echo '</fieldset>';
        echo '<fieldset><legend>Search Results</legend>' . @$search_results . '</fieldset>';
    }
}

// ----------------------------------------------------------------------------
// "Admin > Postmaster > Lists" tab
function bab_pm_lists()
{
    $step = gps('step');

    if ($listToEdit = gps('list')) {
        bab_pm_makeform();
    } else {
        // total lists
        $lvars = array('page','sort','dir','crit','method');
        extract(gpsa($lvars));
        $total = getCount('bab_pm_list_prefs',"1");
        echo '<fieldset id="bab_pm_total-lists"><legend><span class="bab_pm_underhed">Total Lists</span></legend><br />' . $total . '</fieldset>';

        // add lists
        echo '<fieldset id="bab_pm_edit"><legend><span class="bab_pm_underhed"><a href="#" class="show">Add a List</a></span></legend><br /><div class="stuff">';
        bab_pm_makeform();
        echo '</div></fieldset>';

        // manage lists
        if (empty($method)) {
            $method = gps('search_method');
        }

        if (!$sort) {
            $sort = "listName";
        }

        $dir = $dir == 'desc' ? 'desc' : 'asc';

        if ($crit) {
            $critsql = array(
                'name' => "listName rlike '".doSlash($crit)."'",
                'admin email'     => "listAdminEmail rlike '".doSlash($crit)."'",
            );
            $criteria = $critsql[$method];
            $limit = 500;
        }

        if (empty($criteria)) {
            $criteria = '1';
        }

        $gesh = safe_rows_start(
            "*",
            "bab_pm_list_prefs",
            "$criteria order by $sort"
        );

        echo '<fieldset id="bab_pm_list-of-lists"><legend><span class="bab_pm_underhed">List of Lists</span></legend>';
        echo listlist_searching_form($crit,$method);

        if ($gesh) {
            echo '<form method="post" name="longform" class="multi_edit_form">',
            startTable('list'),
            '<tr>',
                // hCell(gTxt('Edit')),
                bab_pm_list_column_head('Name', 'listName', 'postmaster', 1, ''),
                bab_pm_list_column_head('Admin Email', 'listAdminEmail', 'postmaster', 1, ''),
                hCell(gTxt('Description')),
                hCell(gTxt('List Form')),
                bab_pm_column_head(NULL),
            '</tr>';

            while ($a = nextRow($gesh)) {
                extract($a);
                $modbox = fInput('checkbox','selected[]',$listID,'','','','','',$listID);
                $editLink = '<a href="?event=postmaster&step=lists&list=' . $listID . ' ">' . $listName . '</a>';
                $formLink = '<a href="?event=form&step=form_edit&name=' . $listEmailForm . ' ">' . $listEmailForm . '</a>';
                echo "<tr>".n,
                    td($editLink,75),
                    td($listAdminEmail,170),
                    td($listDescription,230),
                    td($formLink,170),
                    td($modbox),
                '</tr>'.n;
            }

            echo "<tr>".n,
                tda(
                    multi_edit(array(
                        'add_all_to_list' => bab_pm_gTxt('add_all_to_list'),
                        'remove_all_from_list' => bab_pm_gTxt('remove_all_from_list'),
                        'delete_lists' => bab_pm_gTxt('delete'),
                    ), 'postmaster', 'postmaster_multi_edit', $page, $sort, $dir, $crit, $method)
                    , ' colspan="5" style="text-align: right; border: none;"'
                ),
            '</tr>';
            echo "</table></form>";
            unset($sort);
        }

        echo '</fieldset>';
    }
}

// ----------------------------------------------------------------------------
// "Admin > Postmaster > Import/Export" tab
function bab_pm_importexport()
{
    echo '<fieldset id="bab_pm_add-subscriber"><legend><span class="bab_pm_underhed">Import Subscribers</span></legend>'
        . bab_pm_file_upload_form(gTxt('upload_file'), 'upload', 'import')
        . '</fieldset>';

    echo '<fieldset id="bab_pm_export-subscribers"><legend><span class="bab_pm_underhed">Export Subscribers</span></legend><div>';

    echo $final = <<<final
<p><a href="?event=postmaster&amp;step=export">Export all subscribers</a></p>
<p><a href="?event=postmaster&amp;step=export&amp;include_header=1">Export all subscribers</a> (include CSV header)</p>
final;

    echo '</div></fieldset>';
}

function bab_pm_quote($str)
{
    // prep field for CSV
    return '"' . str_replace('"', '""', $str) . '"';
}

function bab_pm_import()
{
    global $prefs;

    if (gps('dump_first') == 'on') {
        safe_delete('bab_pm_subscribers', '1=1');
        safe_delete('bab_pm_subscribers_list', '1=1');
    } else {
        /* Scrub the subscriberCatchall column: when bulkadd runs, it flags each email as "latest".
        this is so that if there is some error, we can run a cleanup and delete them. but now we're
        about to run bulkadd again, so presumably, there WERE no errors last time -- so we set a clean slate */

        safe_update('bab_pm_subscribers', "subscriberCatchall = ''", '1');
    }

    $overwrite = ps('overwrite') == 'on' ? true : false;

    $file = $_FILES['thefile'];

    @ini_set('auto_detect_line_endings', '1');

    $fh = fopen($file['tmp_name'], 'r');
    $skipped = array();

    if ($fh) {
        $added = $updated = 0;
        $lists = safe_rows('listID, listName', 'bab_pm_list_prefs', '1');
        $existset = safe_rows('subscriberID, subscriberEmail', 'bab_pm_subscribers', '1');
        $existing = array();

        foreach ($existset as $row) {
            $existing[$row['subscriberID']] = $row['subscriberEmail'];
        }

        while ($row = fgetcsv($fh)) {
            $email = $row[2];
            $sub_lists = $row[3];

            $row = doSlash(array_pad($row, BAB_CUSTOM_FIELD_COUNT + 4, ''));

            if (count($row) < 3 || empty($email)) {
                continue;
            }

            $is_existing = array_search($email, $existing);

            if ($is_existing !== false && !$overwrite) {
                $skipped[] = $email;
            } else {
                $custom_fields = '';

                for ($i = 1; $i <= BAB_CUSTOM_FIELD_COUNT; $i++) {
                    $custom_fields .= "subscriberCustom{$i} = '" . $row[($i + 3)] . "',";
                }

                $md5 = md5(uniqid(rand(),true));
                $subscriber_id = safe_upsert('bab_pm_subscribers', "
                        subscriberFirstName = '{$row[0]}',
                        subscriberLastName = '{$row[1]}',
                        {$custom_fields}
                        subscriberCatchall = 'latest',
                        unsubscribeID = '$md5'",
                        "subscriberEmail = '{$row[2]}'");

                if ($subscriber_id) {
                    if ($is_existing !== false) {
                        $updated++;
                        $subscriber_id = $is_existing;
                    } else {
                        $added++;
                    }

                    $listids = array();
                    safe_delete('bab_pm_subscribers_list',
                        "subscriber_id = $subscriber_id");

                    foreach (explode(',', $sub_lists) as $l) {
                        $l = trim($l);

                        if (empty($l)) {
                            continue;
                        }

                        foreach ($lists as $list) {
                            if (strcasecmp($list['listName'], $l) == 0) {
                                safe_insert('bab_pm_subscribers_list',
                                    "list_id = {$list['listID']}, subscriber_id = $subscriber_id");
                                break;
                            }
                        }
                    }
                } else {
                    // failed to insert subscriber
                }
            }
        }

        $skip_count = count($skipped);

        echo '<div class="bab_pm_alerts">'
            . "<p>Inserted {$added} addresses.</p>"
            . "<p>Updated {$updated} addresses.</p>"
            . ($skip_count > 0 ?
                "<p>The following $skip_count addresses already exist in the database and were skipped: "
                . join(', ', $skipped)
                . '</p>'
                :
                ''
            )
            . '</div>';

    }

    return bab_pm_importexport();
}

function bab_pm_export()
{
    global $prefs;

    ob_end_clean();

    $date = date("YmdHis");
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="pm_export_'.$date.'.csv"');

    $list_table = safe_pfx('bab_pm_list_prefs');
    $map_table = safe_pfx('bab_pm_subscribers_list');
    $sub_table = safe_pfx('bab_pm_subscribers');

    $custom_fields = '';

    for ($i = 1; $i < BAB_CUSTOM_FIELD_COUNT; $i++) {
        $custom_fields .= "S.subscriberCustom{$i}, ";
    }

    // get subscribers data, build file
    $q = <<<EOSQL
SELECT S.subscriberID, S.subscriberFirstName, S.subscriberLastName, S.subscriberEmail,
    {$custom_fields}
    GROUP_CONCAT(L.listName ORDER BY L.listName SEPARATOR ', ') as subscriberLists
FROM `$sub_table` as S left join `$map_table` as MAP ON S.subscriberID=MAP.subscriber_id
    left join `$list_table` as L ON MAP.list_id=L.listID
GROUP BY S.subscriberID
ORDER BY S.subscriberID
EOSQL;

    $subscribers = getRows($q);

    if (gps('include_header') == 1) {
        $fields = array(
            bab_pm_gTxt('subscriberFirstName'),
            bab_pm_gTxt('subscriberLastName'),
            bab_pm_gTxt('subscriberEmail'),
            bab_pm_gTxt('subscriberLists')
        );

        for ($i = 1; $i <= BAB_CUSTOM_FIELD_COUNT; $i++) {
            $fields[] = $prefs[_bab_prefix_key("subscriberCustom{$i}")];
        }

        echo str_replace(':', '', implode(',', $fields)) . "\n";
    }

    foreach ($subscribers as $subscriber) {
        extract($subscriber);

        $row = array(
            bab_pm_quote($subscriberFirstName),
            bab_pm_quote($subscriberLastName),
            bab_pm_quote($subscriberEmail),
            bab_pm_quote($subscriberLists)
        );

        for ($i = 1; $i <= BAB_CUSTOM_FIELD_COUNT; $i++) {
            $n = 'subscriberCustom' . $i;
            $row[] = bab_pm_quote(@$$n);
        }

        echo implode(',', $row) . "\n";
    }

    exit();
}


function bab_pm_pref_func($func, $name, $val, $size = '')
{
    if ($func == 'text_input') {
        // name mangle to prevent errors in other porrly coded plugins
        $func = 'bab_pm_text_input';
    } else {
        $func = (is_callable('pref_'.$func) ? 'pref_'.$func : $func);
    }

    return call_user_func($func, $name, $val, $size);
}

function bab_pm_text_input($name, $val, $size = '')
{
    return fInput('text', $name, $val, 'edit', '', '', $size, '', $name);
}

function bab_pm_prefs()
{
    echo n.n.'<form method="post" action="index.php">'.
        n.n.startTable('list').
        n.n.tr(
            tdcs(
                hed(bab_pm_gTxt('Preferences'), 1)
            , 3)
        );

    $rs = safe_rows_start('*', 'txp_prefs', "event = 'bab_pm' order by position");

    while ($a = nextRow($rs)) {
        $label_name = bab_pm_gTxt(str_replace('bab_pm-', '', $a['name']));

        $label = ($a['html'] != 'yesnoradio') ? '<label for="'.$a['name'].'" class="'.$a['name'].'">'.$label_name.'</label>' : $label_name;

        $out = tda($label, ' style="text-align: right; vertical-align: middle;"');

        if ($a['html'] == 'text_input') {
            $out.= td(
                bab_pm_pref_func('text_input', $a['name'], $a['val'], 20)
            );
        } else {
            $out.= td(bab_pm_pref_func($a['html'], $a['name'], $a['val']));
        }

        echo tr($out);
    }

    echo n.n.tr(
        tda(
            fInput('submit', 'Submit', gTxt('save'), 'publish').
            n.sInput('prefs_save').
            n.eInput('postmaster')
        , ' colspan="3" class="noline"')
    ).
    n.n.endTable().n.n.'</form>';
}


function bab_pm_prefs_save()
{
    $prefnames = safe_column("name", "txp_prefs", "event = 'bab_pm'");

    $post = doSlash(stripPost());

    foreach($prefnames as $prefname) {
        if (isset($post[$prefname])) {
            if ($prefname == 'siteurl')
            {
                $post[$prefname] = str_replace("http://",'',$post[$prefname]);
                $post[$prefname] = rtrim($post[$prefname],"/ ");
            }

            safe_update(
                "txp_prefs",
                "val = '".$post[$prefname]."'",
                "name = '".doSlash($prefname)."'"
            );
        }
    }

    update_lastmod();

    $alert = bab_pm_preferences('prefs_saved');
    echo "<div class=\"bab_pm_alerts\">$alert</div>";

    bab_pm_prefs();
}

// ----------------------------------------------------------------------------
// Bulk Mail, in two parts (Initialize, Mail)

// ----------------------------------------------------------------------------
// Initialize

function bab_pm_initialize_mail()
{
    // no need to check radio (checked in eop)
    @session_start();

    global $listAdminEmail, $headers, $mime_boundary, $bab_pm_PrefsTable, $bab_pm_SubscribersTable, $row, $rs; // $row (list), $rs (article) are global for bab_pm_data

    $bab_pm_radio = (!empty($_REQUEST['bab_pm_radio'])) ? gps('bab_pm_radio') : gps('radio');

    $sep = IS_WIN ? "\r\n" : "\n";

    include_once txpath.'/publish.php'; // this line is required

    // get list data (this is so we only perform the query once)
    $listToEmail = (!empty($_REQUEST['listToEmail'])) ? gps('listToEmail') : gps('list');
    $row = safe_row('*', 'bab_pm_list_prefs', "listname = '".doSlash($listToEmail)."'");

    extract($row); // go ahead and do it because you need several of the variables in initialize

    // get article data here, so we only do query one time
    $artID = gps('artID');

    if (!empty($artID)) {
        // bypass if this is called from the send screen
        $rs = safe_row(
            "*, unix_timestamp(Posted) as sPosted,
            unix_timestamp(LastMod) as sLastMod",
            "textpattern",
            "ID=".doSlash($artID)
        );

        @populateArticleData($rs); // builds $thisarticle (for article context error)

        // if no subject line, use article title
        if (empty($listSubjectLine)) {
            $listSubjectLine = $rs['Title'];
        }
    }

    $newSubject = gps('subjectLine');
    $subjectLineSource = (!empty($newSubject)) ? 'newSubject' : 'listSubjectLine';

    $sendFrom = gps('sendFrom');
    $email_from = empty($sendFrom) ? $listAdminEmail : $sendFrom;

    $subject = parse($$subjectLineSource);

    // set TOTAL number of subscribers in list (for progress bar calculation)
    if (isset($listID)) {
        $map_table = safe_pfx('bab_pm_subscribers_list');
        $sub_table = safe_pfx('bab_pm_subscribers');

        $q = <<<EOSQL
SELECT COUNT(*)
FROM `$sub_table` as S inner join `$map_table` as MAP ON S.subscriberID=MAP.subscriber_id
WHERE MAP.list_id = $listID
EOSQL;
        $bab_pm_total = getThing($q);
        $bab_pm_total = $bab_pm_total ? $bab_pm_total : 0;
    } else {
        $bab_pm_total = 0;
    }

    // set mime boundary, so that only happens once
    $semi_rand = md5(time());
    $mime_boundary = "Multipart_Boundary_x{$semi_rand}x";

    $headers = array(
        'From' => $email_from,
        'Reply-To' => $email_from,
        'X-Mailer' => 'Textpattern/Postmaster',
    );

    // Additional headers required if using regular mail.
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
       $headers['MIME-Version'] = '1.0';
       $headers['Content-Transfer-Encoding'] = '8bit';
       $headers['Content-Type'] = 'text/plain';
    }

    // if use override is selected, then overwrite the listEmailForm variable
    if (gps('use_override')) {
        $listEmailForm = gps('override_form');
    }

    // set email template(s), so that only happens once
    $listEmailForm = trim($listEmailForm);

    if (!empty($listEmailForm)) {
        $theForm = fetch('Form','txp_form','name',"$listEmailForm");

        if ($theForm) {
            $template = bab_pm_extract($theForm);
        }
    }

    // test to confirm that we actually have a form, otherwise use default
    if (empty($template) || !$template) {
$template['html'] = $template['text'] = $template['combined'] = <<<eop_form
<txp:author /> has posted a new article at <txp:site_url />.
Read article at: <txp:bab_pm_data display="link" />
Unsubscribe: <txp:bab_pm_unsubscribeLink />
eop_form;
    }

    //echo $template;

    // send all our initialized to bab_pm_bulk_mail
    bab_pm_bulk_mail($bab_pm_total, $bab_pm_radio, $subject, @$thisarticle, $template); // send all info to mail through function
}

// ----------------------------------------------------------------------------
// Mail

function bab_pm_bulk_mail($bab_pm_total, $bab_pm_radio, $subject, $thisarticle, $template)
{
    global $prefs, $production_status;

    $usePhpMailer = false;
    $mail = null;

    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        if ($production_status === 'debug') {
            $mail->SMTPDebug  = 3;
        } elseif ($production_status === 'testing') {
            $mail->SMTPDebug  = 2;
        }

        // Bypass the fact that PHPMailer clashes with <txp:php>.
        $mail::$validator = 'phpinternal';

        $usePhpMailer = true;
    }

    echo '<p class=bab_pm_subhed>BULK MAIL</p>';
    echo '<p>Currently mailing: ' . $subject . '</p>';

    // ----- set globals for library funcs

    global $headers, $mime_boundary, $bab_pm_unsubscribeLink, $bab_pm_PrefsTable, $bab_pm_SubscribersTable, $row, $rs;
    global $subscriberName, $subscriberFirstName, $subscriberLastName, $subscriberEmail, $subscriberLists;

    $unsubscribe_url = trim($prefs[_bab_prefix_key('default_unsubscribe_url')]);

    if (empty($unsubscribe_url)) {
        $unsubscribe_url = trim($row['listUnsubscribeUrl']);
    }

    for ($i = 1; $i <= BAB_CUSTOM_FIELD_COUNT; $i++) {
        $n = "subscriberCustom{$i}";
        global $$n; // required (extracted in foreach, then sent to bab_pm_data)
    }

    $sep = IS_WIN ? "\r\n" : "\n";

    // prep Title, Body and Excerpt
    @extract($rs);
    parse(@$Title);
    parse(@$Body);
    $Body = str_replace("\r\n", "\n", @$Body);
    $Body = str_replace("\r", "\n", $Body);
    $Body = str_replace("\n", $sep, $Body);
    parse(@$Excerpt);
    $Excerpt = str_replace("\r\n", "\n", @$Excerpt);
    $Excerpt = str_replace("\r", "\n", $Excerpt);
    $Excerpt = str_replace("\n", $sep, $Excerpt);
    $smtp_from = get_pref('smtp_from');

    extract($row); // need list data to parse template

    // ----- check bab_pm_radio

    // set $subscribers to be chosen list of subscribers
    if ($bab_pm_radio == 1) {
        $map_table = safe_pfx('bab_pm_subscribers_list');
        $sub_table = safe_pfx('bab_pm_subscribers');
        $subsQuery = <<< EOSQL
SELECT S.*
FROM `$sub_table` as S inner join `$map_table` as MAP ON S.subscriberID=MAP.subscriber_id
WHERE MAP.list_id = $listID AND flag != 'mailed'
EOSQL;
        $subscribers = getRows("$subsQuery");

        if (!$subscribers) { // if there are NO subscribers NOT mailed, go to coda
            bab_pm_mail_coda();
        }

        $sub_total = count($subscribers);
        $sent = ($bab_pm_total-$sub_total);
        $remaining = round((1-($sent/$bab_pm_total))*100);

        echo $status_report = <<<status_report
<div class=bab_pm_alerts>
    <img src="/images/percentImage.png" alt="$remaining" style="background-position: {$remaining}% 0%;" class='pm_prog_bar' /><br />

            $sub_total left ...

</div>

<p style="padding:10px;text-align:center;">Please don't close this window until mailing is complete. You will see a message here when it's done.</p>

status_report;

    }

    // set $subscribers to be an an array of an array of one
    if ($bab_pm_radio == 2) {
        $testSubscriber = array(
            'subscriberID'        => '0',
            'subscriberFirstName' => 'Test',
            'subscriberLastName'  => 'User',
            'subscriberEmail'     => $listAdminEmail,
            'unsubscribeID'       => '12345'
        );

        $subscribers = array($testSubscriber);
    }

    // begin batch
    $email_batch = bab_pm_preferences('emails_per_batch');

    if (empty($email_batch) or !is_numeric($email_batch)) {
        $email_batch = 10;
    }

    $i = 1; // set internal counter

    foreach ($subscribers as $subscriber) {
        if ($i <= $email_batch) {
            @extract($subscriber);

            if (empty($subscriberLastName) && empty($subscriberFirstName)) {
                $subscriberName = "Subscriber";
            } else {
                $subscriberName = @$subscriberFirstName . ' ' . @$subscriberLastName;
            }

            $url = $unsubscribe_url;

            if (!empty($url)) {
                $bab_pm_unsubscribeLink = $url . (strrchr($url, '?') ? '&' : '?') . 'uid=' . urlencode($unsubscribeID);
            } else {
                $bab_pm_unsubscribeLink = '';
            }

            // all necessary variables now defined, parse email template
            $email = parse(empty($template['html']) ? $template['combined'] : $template['html']);
            $email = str_replace("\r\n", "\n", $email);
            $email = str_replace("\r", "\n", $email);
            $email = str_replace("\n", $sep, $email);
            callback_event_ref('mem_postmaster.message', 'html', 0, $template, $email);

            $email = mb_convert_encoding($email, "HTML-ENTITIES", "UTF-8");

            $plain = parse(empty($template['text']) ? $template['combined'] : $template['text']);
            callback_event_ref('mem_postmaster.message', 'text', 0, $template, $plain);

            if ($usePhpMailer) {
                try {
                    $smtp_host = get_pref('smtp_host');
                    $smtp_user = get_pref('smtp_user');
                    $smtp_pass = get_pref('smtp_pass');
                    $smtp_port = get_pref('smtp_port');

                    if ($smtp_host) {
                        $mail->IsSMTP();
                        $mail->SMTPAuth = true;
                        $mail->Host = $smtp_host;
                        $mail->Username = $smtp_user;
                        $mail->Password = $smtp_pass;
                        $mail->SMTPSecure = 'tls';
                        $mail->Port = $smtp_port;
                    }

                    $mail->IsHTML(true);
                    $mail->addAddress($subscriberEmail);
                    $mail->Subject = $subject;
                    $mail->Body    = $email;
                    $mail->AltBody = $plain;

                    if (is_valid_email($smtp_from)) {
                        $mail->Sender = $smtp_from;
                        $mail->addReplyTo($smtp_from, parse('<txp:site_name />'));
                        $mail->setFrom($smtp_from, parse('<txp:site_name />'), false);
                    } else {
                        $mail->addReplyTo($headers['From'], parse('<txp:site_name />'));
                        $mail->setFrom($headers['From'], parse('<txp:site_name />'));
                    }

                    $ret = $mail->send();

                } catch (PHPMailer\PHPMailer\Exception $e) {
                   echo $e->errorMessage();
                } catch (\Exception $e) {
                   echo $e->getMessage();
                }

                $mail->clearAddresses();
                $mail->clearReplyTos();
                $mail->clearAttachments();
            } else {
                $headerStr = '';
                $message = parse($template['combined']);

                foreach ($headers as $hkey => $hval) {
                    $headerStr .= $hkey.': '.$hval.$sep;
                }

                $ret = mail($subscriberEmail, $subject, $message, $headerStr);
            }

            $i++; // ---- update internal counter

            // mark address as "mailed"
            $result = safe_update('bab_pm_subscribers', "flag = 'mailed'", "subscriberEmail='".doSlash($subscriberEmail)."'");

            echo "<p>Mail sent to $subscriberEmail</p>";
        } else {
            break;
        }
    }

    if ($bab_pm_radio == 1) {
        $email_batch_delay = $prefs[_bab_prefix_key('email_batch_delay')];

        if (empty($email_batch_delay) || !is_numeric($email_batch_delay)) {
            $email_batch_delay = 3;
        }

        header("Cache-Control: no-store");
        header("Refresh: ".$email_batch_delay.";");

        exit;
    }

    if ($bab_pm_radio == 2) {
        echo '<div class=bab_pm_alerts>Your mailing is complete.</div>';
    }
} // end bulk mail

// ----------------------------------------------------------------------------
// Extract plaintext and HTML chunks from a form by parsing for
// <txp:bab_pm_mime> or <bab::pm_mime>

function bab_pm_extract($form)
{
    $out = array(
        'text'     => '',
        'html'     => '',
        'combined' => $form,
    );

    $needles = array(
        "<txp:bab_pm_mime",
        "<bab::pm_mime",
    );

    $needle = '';

    foreach ($needles as $string) {
        if (strpos($form, $string, 0) !== false) {
            $needle = $string;
        }
    }

    if (!$needle) {
        // @todo 4.9.0+ read html_email pref and set either 'text' or 'html' by default accordingly.
        return $out;
    }

    $positions = array();
    $lastPos = 0;
    $skip = strlen($needle);
    preg_match_all('|'.$needle.'\stype="(.*?)"\s/>|', $form, $types);

    $offset = 0;

    while (($lastPos = strpos($form, $needle, $lastPos)) !== false) {
        $endtagPos = strpos($form, "/>", $lastPos) + 2;

        if ($offset > 0) {
            $positions[$types[1][$offset-1]]['end'] = $lastPos;
        }

        $positions[$types[1][$offset]]['start'] = $endtagPos;
        $lastPos = $endtagPos;
        $offset++;
    }

    $positions[$types[1][$offset-1]]['end'] = strlen($form);

    foreach ($positions as $type => $blocks) {
        $out[$type] = trim(substr($form, $blocks['start'], $blocks['end'] - $blocks['start']));
    }

    unset ($out['end']);

    return $out;
}

// ----------------------------------------------------------------------------
// Navigation

function bab_pm_mastheadNavigation()
{
    echo '<center><p class="bab_pm_hed">POSTMASTER</p>';
$layout = <<<layout
<td class="navlink2">hello</td><td class="navlink2"><a href="?event=postmaster&step=listlist"  class="plain">Lists</a></td><td class="navlink2"><a href="?event=postmaster&step=add"  class="plain">Add</a></td><td class="navlink2"><a href="?event=postmaster&step=importexport"  class="plain">Import/Export</a></td>
 </tr></table><Br>
 <table width="600" class="bab_pm_contenttable"><tr><td valign="top">
layout;

    echo $layout;
}

// ----------------------------------------------------------------------------
// com_connect
function bab_pm_channels($atts, $thing = null)
{
    global $bab_pm_channel;

    extract(lAtts(array(
        'break'   => '',
        'class'   => __FUNCTION__,
        'form'    => null,
        'html_id' => '',
        'id'      => null,
        'name'    => null,
        'sort'    => 'listName',
        'wraptag' => '',
    ), $atts));

    $where = array();
    $has_content = $thing || $form;
    $safe_sort = sanitizeForSort($sort);

    if ($name) {
        $where[] = "listName IN ('".join("','", doSlash(do_list_unique($name)))."')";
    }

    if ($id) {
        $id = join(',', array_map('intval', do_list_unique($id, array(',', '-'))));
        $where[] = "listID IN ($id)";
    }

    // Get all lists if no filters supplied.
    if (!$where) {
        $where[] = "1=1";
    }

    // Order of ids in 'id' attribute overrides default 'sort' attribute.
    if (empty($atts['sort']) && $id) {
        $safe_sort = "FIELD(listID, $id)";
    }

    $whereClause = join(" AND ", $where);

    $qparts = array(
        $whereClause,
        "ORDER BY ".$safe_sort,
    );

    $rs = safe_rows('*', 'bab_pm_list_prefs', join(' ', $qparts));

    if ($rs) {
        $out = array();
        $count = 0;
        $last = count($rs);

        foreach ($rs as $row) {
            ++$count;
            $bab_pm_channel = $row;
            $bab_pm_channel['is_first'] = ($count == 1);
            $bab_pm_channel['is_last'] = ($count == $last);

            if (!$has_content) {
                $thing = '<txp:bab_pm_channel type="listName" />';
            }

            $out[] = ($thing) ? parse($thing) : parse_form($form);
        }

        if ($out) {
            return doWrap($out, $wraptag, compact('break', 'class', 'html_id'));
        }
    }

    return '';
}

function bab_pm_channel($atts, $thing = null)
{
    global $bab_pm_channel;
    static $cache = array();

    extract(lAtts(array(
        'class'   => '',
        'break'   => '',
        'escape'  => true,
        'id'      => '',
        'name'    => '',
        'type'    => 'listName',
        'wraptag' => '',
    ), $atts));

    $validItems = array(
        'listID',
        'listName',
        'listDescription',
        'listAdminEmail',
        'listUnsubscribeUrl',
        'listEmailForm',
        'listSubjectLine',
        'catchall'
    );

    $where = '';
    $out = array();
    $type = do_list($type);
    empty($escape) or $escape = compact('escape');

    if ($id) {
        if (isset($cache['i'][$id])) {
            $rs = $cache['i'][$id];
        } else {
            $where = 'listID = '.intval($id).' LIMIT 1';
        }
    } elseif ($name) {
        if (isset($cache['n'][$name])) {
            $rs = $cache['n'][$name];
        } else {
            $where = "listName = '".doSlash($name)."' LIMIT 1";
        }
    } elseif ($bab_pm_channel) {
        $id = (int) $bab_pm_channel['listID'];
        $rs = $cache['i'][$id] = $bab_pm_channel;
    } else {
        return false;
    }

    if ($where) {
        $rs = safe_row("*", 'bab_pm_list_prefs', $where);
    }

    if ($rs) {
        $id = (int) $rs['listID'];
        $cache['i'][$id] = $rs;

        foreach ($type as $item) {
            if (in_array($item, $validItems)) {
                if (isset($rs[$item])) {
                    $out[] = $escape ? txp_escape($escape, $rs[$item]) : $rs[$item];
                }
            } else {
                trigger_error(gTxt('invalid_attribute_value', array('{name}' => $item)), E_USER_NOTICE);
            }
        }
    }

    return doWrap($out, $wraptag, $break, $class);
}

// com_connect submission handler.
function bab_pm_comconnect_submit()
{
    global $com_connect_values;

    extract($com_connect_values);
    $bab_pm_SubscribersTable = safe_pfx('bab_pm_subscribers');

    $subscriberEmail = @trim($subscriberEmail);
    $unsubscribeID = @trim($unsubscribeID);
    $doSubscribe = @trim($doSubscribe);
    $unsubscribe = @trim($unsubscribe);

    // check if this IS a Postmaster com_connect_submit; if not, return
    if (!$subscriberEmail && !$unsubscribeID) {
        return;
    }

    // check if the doSubscribe option is included; if so, does it say no? if no, return
    if ($doSubscribe && strtolower($doSubscribe) == 'no') {
        return;
    }

    // check if unsubscribe is included; if so, does it say "on"? if "on", unsubscribe and return
    $is_unsubscribe = strtolower($unsubscribe);
    $is_unsubscribe = $is_unsubscribe == 'on' || $is_unsubscribe == 'yes';

    if ($unsubscribeID) {
        $where = "unsubscribeID='".doSlash($unsubscribeID)."'";
    } else {
        $where = "subscriberEmail='".doSLash($subscriberEmail)."'";
    }

    $subscriber_id = (int)safe_field('subscriberID', 'bab_pm_subscribers', $where);

    if ($is_unsubscribe) {
        if ($subscriber_id) {
            // remove from lists
            safe_delete('bab_pm_subscribers_list', "subscriber_id=$subscriber_id");
            // delete subscription record
            if (safe_delete('bab_pm_subscribers', "subscriberID=$subscriber_id")) {
                return '';
            }
        }

        return "There was an error. Please contact the administrator of this website.";
    }

    $fields = array('FirstName', 'LastName', 'Email');

    for ($i = 1; $i <= BAB_CUSTOM_FIELD_COUNT; $i++) {
        $fields[] = "Custom{$i}";
    }

    $set = array("unsubscribeID = '". md5(uniqid(rand(),true)) ."'");

    foreach ($fields as $f) {
        $var = 'subscriber' . $f;
        $val = isset($$var) ? $$var : '';

        // ignore empty values on update
        if (!empty($val) || !$subscriber_id) {
            $set[] = 'subscriber' . $f . " = '" . doSlash($val) . "'";
        }
    }

    if (!$subscriber_id) {
        // add new subscriber
        $subscriber_id = safe_insert('bab_pm_subscribers', implode(', ', $set));
        $subscriptions = false;
    } else {
        // update existing subscriber details
        safe_update('bab_pm_subscribers', implode(', ', $set), "subscriberID = $subscriber_id");

        // delete list of current memberships ready for the new set
        safe_delete('bab_pm_subscribers_list', "subscriber_id = $subscriber_id");
    }

    if ($subscriber_id) {
        // get lists
        $lists = safe_rows('listID, listName', 'bab_pm_list_prefs', '1=1');

        $sub_list_value = ps('subscriberLists');
        $sub_lists = is_array($sub_list_value) ? $sub_list_value : explode(',', $sub_list_value);

        foreach ($sub_lists as $slist) {
            foreach ($lists as $l) {
                $list_id = (int)$l['listID'];
                $list_name = trim($l['listName']);

                // if the list name is valid and not already subscribed.
                if (
                    (!is_numeric($slist) && strcasecmp($list_name, $slist) == 0)
                    || (is_numeric($slist) && $list_id == $slist)
                ) {
                    // subscribe
                    safe_insert('bab_pm_subscribers_list',
                        "list_id = $list_id, subscriber_id = $subscriber_id");
                }
            }
        }
    }
}

// ----------------------------------------------------------------------------
// ZCR form, date input
function bab_pm_time($atts)
{
    $today = date("F j, Y, g:i a");

    extract(lAtts(array(
        'custom_field' => '',
    ), $atts));

$form_line = <<<yawp
<input type="hidden" name="$custom_field" value="$today" >
yawp;

    return $form_line;
}

// ----------------------------------------------------------------------------
// email on post -- this is called after you click "Save"
function bab_pm_eop($evt, $stp)
{
    $bab_pm_PrefsTable = safe_pfx('bab_pm_list_prefs');
    $bab_pm_SubscribersTable = safe_pfx('bab_pm_subscribers');

    $bab_pm_radio = gps('bab_pm_radio'); // this is whether to mail or not, or test
    $listToEmail = gps('listToEmail'); // this is the list name
    $artID = gps('artID');
    $sendFrom = gps('sendFrom');

    // ---- scrub the flag column for next time:
    if ($bab_pm_radio) {
        $result = safe_query("UPDATE $bab_pm_SubscribersTable SET flag = NULL");

        $path = "?event=postmaster&step=initialize_mail&radio=$bab_pm_radio&list=$listToEmail&artID=$artID";

        if (!empty($sendFrom)) {
            $path .= '&sendFrom='.urlencode($sendFrom);
        }

        header("HTTP/1.x 301 Moved Permanently");
        header("Status: 301");
        header("Location: ".$path);
        header("Connection: close");
    }
}

// ----------------------------------------------------------------------------
// the ifs (if the delete button is clicked, etc)
function bab_pm_ifs()
{
    $bab_pm_PrefsTable = safe_pfx('bab_pm_list_prefs');
    $bab_pm_SubscribersTable = safe_pfx('bab_pm_subscribers');

    // if the "add subscriber" button has been clicked

    $if_add = ps('newSubscriberFirstName') or ps('newSubscriberLastName') or ps('newSubscriberEmail') or ps('newSubscriberLists');

    $fields_array = array('newSubscriberFirstName', 'newSubscriberLastName', 'newSubscriberEmail');

    for ($i = 1; $i <= BAB_CUSTOM_FIELD_COUNT; $i++) {
        $n = "newSubscriberCustom{$i}";
        $fields_array[] = $n;
        $if_add |= ps($n);
    }

    if ($if_add) {
        extract(doSlash(gpsa($fields_array)));

        $md5 = md5(uniqid(rand(),true));

        $sql_fields = '';

        foreach ($fields_array as $field) {
            $sql_fields .= "`{$field}` = '" . $$field . "', ";
        }

        $sql_fields .= "`subscriberCatchall` = '',";
        $sql_fields .= "`unsubscribeID` = '{$md5}'";

        // We remove the 'new' string from each field.
        $sql_fields = preg_replace('/`new/i', '`', $sql_fields);

        $subscriber_id = safe_insert('bab_pm_subscribers', $sql_fields);

        $lists = gps('newSubscriberLists');

        if (is_array($lists)) {
            foreach ($lists as $l) {
                $list_id = doSlash($l);
                safe_insert('bab_pm_subscribers_list',
                    "list_id = $list_id, subscriber_id = $subscriber_id");
            }
        }

        $alert = bab_pm_preferences('subscriber_add');

        echo "<div class=bab_pm_alerts>$alert</div>";
    }

    // if the "add list" button has been clicked

    if (ps('newListName') or ps('newListDescription') or ps('newListAdminEmail') or ps('newListUnsubscribeUrl') or ps('newListEmailForm') or ps('newListSubjectLine')) {
        $addNewListArray = array(doSlash(ps('newListName')), doSlash(ps('newListDescription')), doSlash(ps('newListAdminEmail')), doSlash(ps('newListUnsubscribeUrl')), doSlash(ps('newListEmailForm')), doslash(ps('newListSubjectLine')));
        $strSQL = safe_query("INSERT INTO $bab_pm_PrefsTable values (NULL,'$addNewListArray[0]','$addNewListArray[1]','$addNewListArray[2]','$addNewListArray[3]','$addNewListArray[4]','$addNewListArray[5]','')");
        $alert = bab_pm_preferences('list_add');
        echo "<div class=bab_pm_alerts>$alert</div>";
    }

    if (ps('step') == 'postmaster_multi_edit') {
        // multiedit
        $selected = ps('selected');
        $method = ps('edit_method');

        $selected_list_id = ps('selected_list_id');
        $list_id = doSlash($selected_list_id);

        if ($selected) {
            foreach ($selected as $s) {
                $sid = doSlash($s);

                if ($method == 'delete') {
                    // delete subscriber
                    safe_delete('bab_pm_subscribers', "subscriberID = $sid");
                    // delete subscriber list map
                    safe_delete('bab_pm_subscribers_list', "subscriber_id = $sid");
                } elseif ($method == 'add_to_list') {
                    // ignore error. It'll most likely be from unique constraint
                    @safe_insert('bab_pm_subscribers_list', "subscriber_id = $sid, list_id = $list_id");
                } elseif ($method == 'remove_from_list') {
                    safe_delete('bab_pm_subscribers_list', "subscriber_id = $sid AND list_id = $list_id");
                } elseif ($method == 'delete_lists' || $method == 'remove_all_from_list') {
                    // unsubscribe all from list
                    safe_delete('bab_pm_subscribers_list', "list_id = $sid");

                    if ($method == 'delete_lists') {
                        // remove list
                        safe_delete('bab_pm_list_prefs', "listID = $sid");
                    }
                } elseif ($method == 'add_all_to_list') {
                    // remove all subs for list to prevent constraint violation
                    safe_delete('bab_pm_subscribers_list', "list_id = $sid");
                    // add everyone to list
                    safe_query('INSERT INTO '.PFX.'bab_pm_subscribers_list (list_id, subscriber_id)'
                        . " SELECT $sid as list_id, subscriberID as subscriber_id FROM ".PFX."bab_pm_subscribers");
                }
            }

            $alert = bab_pm_preferences('subscribers_'.$method);
        } else {
            $alert = 'You must select at least 1 checkbox to do that';
        }

        echo '<div class="bab_pm_alerts">'
            . $alert
            . '</div>';
    }

    $editSubscriberId = ps('editSubscriberId');

    // if the "edit subscriber" button has been clicked
    if ($editSubscriberId) {
        $edit_fields = array('editSubscriberFirstName', 'editSubscriberLastName', 'editSubscriberEmail');

        for ($i = 1; $i <= BAB_CUSTOM_FIELD_COUNT; $i++) {
            $edit_fields[] = "editSubscriberCustom{$i}";
        }

        extract(doSlash(gpsa($edit_fields)));

        $sql_fields = array();

        foreach ($edit_fields as $field) {
            $n = 's' . substr($field, 5);
            $sql_fields[] = "`{$n}` = '" . $$field . "'";
        }

        $sql_fields = implode(', ', $sql_fields);

        safe_update('bab_pm_subscribers', $sql_fields, "`subscriberID` = {$editSubscriberId}");

        $lists = ps('editSubscriberLists');

        safe_delete('bab_pm_subscribers_list', "subscriber_id = $editSubscriberId");

        if (is_array($lists)) {
            foreach ($lists as $l) {
                $list_id = doSlash($l);
                safe_insert('bab_pm_subscribers_list',
                    "subscriber_id = $editSubscriberId, list_id = $list_id");
            }
        }

        $alert = bab_pm_preferences('subscriber_edit');
        echo "<div class=bab_pm_alerts>$alert</div>";
    }

    // if the "edit list" button has been clicked
    if (ps('editListName') or ps('editListDescription') or ps('editListAdminEmail') or ps('editListUnsubscribeUrl') or ps('editListEmailForm') or ps('editListSubjectLine')) {
        $editListArray = array(doSlash(ps('editListName')), doSlash(ps('editListDescription')), doSlash(ps('editListAdminEmail')), doSlash(ps('editListUnsubscribeUrl')), doSlash(ps('editListEmailForm')), doSlash(ps('editListID')), doslash(ps('editListSubjectLine')));

        $strSQL = safe_query("update $bab_pm_PrefsTable set
listName='$editListArray[0]', listDescription='$editListArray[1]', listAdminEmail='$editListArray[2]', listUnsubscribeUrl='$editListArray[3]', listEmailForm='$editListArray[4]', listSubjectLine='$editListArray[6]' where listID=$editListArray[5]");
        $alert = bab_pm_preferences('list_edit');

        echo "<div class=bab_pm_alerts>$alert</div>";
    }
}

function bab_pm_styles()
{
    $css_encoded = safe_field('css', 'txp_css', "name LIKE 'mem_postmaster'");

    if ($css_encoded) {
        $css = base64_decode($css_encoded);

        if ($css === false) {
            $css = $css_encoded;
        }

        echo n . '<style type="text/css">' . n
            . $css
            . n . '</style>' . n;
    } else {
        echo $bab_pm_styles = <<<bab_pm_styles
<style type="text/css">
#bab_pm_master {

}
#bab_pm_master fieldset{
    padding:10px;
}
#bab_pm_master legend{
    font: small-caps bold 12px Georgia; color: black;
}
#bab_pm_nav {
    width:100%;
}
#bab_pm_content {
    width:600px;
    margin-right:auto;
    margin-left:auto;
    margin-top:-20px;
}
.bab_pm_alerts {
    color:red;
    padding:10px;
    margin-top:10px;
    margin-bottom:10px;
    border:1pt dotted red;
    text-align:center;
}

.stuff { display:none; }
.csv_form {margin-left:200px;}

/* mrdale's */

#bab_pm_edit p {
    float:left;
    clear:left;
    width:122px;
    height:22px;
    background:#eee;
    margin:3px;
/*  line-height:200%; */
    text-align:right;
    padding-right:5px;
}
#bab_pm_edit textarea {
    float:right;
    clear:none;
    border:1px inset gray;
    background:#fff;
    padding-left:3px;
}

.bab_pm_input_group {
    margin-bottom: 10px;
    float: right;
    clear: none;
    padding-left: 3px;
    width: 75%;
    height: 100%;
}
dl.bab_pm_form_input {
    width: 100%;
    clear: left;
}
dl.bab_pm_form_input dt {
    width: 120px;
    text-align: right;
    clear: left;
    float: left;
    position: relative;
}
dl.bab_pm_form_input dd {
    margin-left: 125px;
    clear: right;
}
dl.bab_pm_form_input dd input[type=text] {
    width: 300px;
}

#bab_pm_edit input.smallerbox {
    margin:5px 0;
    float:right
}

.pm_prog_bar {
    background: white url(/images/percentImage_back.png) no-repeat top left;
    padding: 0;
    margin: 5px 0 0 0;
    background-position: 0 0;
}
</style>
bab_pm_styles;
    }
}

function bab_pm_poweredit()
{
    $lists = safe_rows('listID, listName', 'bab_pm_list_prefs', '1 order by listName');

    $list_options = '';

    foreach ($lists as $l) {
        $list_options .= '<option value="'.doSlash($l['listID']).'">'.htmlspecialchars($l['listName']).'</option>';
    }

    echo <<<EOJS
<script type="text/javascript">
<!--
        function poweredit(elm)
        {
            var something = elm.options[elm.selectedIndex].value;

            // Add another chunk of HTML
            var pjs = document.getElementById('js');

            if (pjs == null) {
                var br = document.createElement('br');
                elm.parentNode.appendChild(br);

                pjs = document.createElement('P');
                pjs.setAttribute('id','js');
                elm.parentNode.appendChild(pjs);
            }

            if (pjs.style.display == 'none' || pjs.style.display == '') {
                pjs.style.display = 'block';
            }

            switch (something) {
                case 'add_to_list':
                case 'remove_from_list':
                    var lists = '<select name=\"selected_list_id\" class=\"list\">{$list_options}</select>';
                    pjs.innerHTML = '<span>List: '+lists+'</span>';
                    break;
                default:
                    pjs.innerHTML = '';
                    break;
            }

            return false;
        }
-->
</script>
EOJS;
}

#===========================================================================
#   Strings for internationalisation...
#===========================================================================
global $_bab_pm_l18n;

$_bab_pm_l18n = array(
    'bab_pm'                => 'Postmaster plugin',
    # --- the following are used as labels for PM prefs...
    'subscriberFirstName'   => 'First Name',
    'subscriberLastName'    => 'Last Name',
    'subscriberEmail'   => 'Email',
    'subscriberLists'   => 'Lists',
    'subscribers_per_page'  => 'Subscribers per page',
    'emails_per_batch'      => 'Emails per batch',
    'email_batch_delay'     => 'Batch delay (seconds)',
    'form_select_prefix'    => 'Form Select Prefix',
    'default_unsubscribe_url'   => 'Default Unsubscribe URL',
    # --- the following are used in the PM interface...
    'add_to_list'       => 'Add to List',
    'remove_from_list'  => 'Remove from List',
    'add_all_to_list'   => 'Add everyone to List',
    'remove_all_from_list'  => 'Remove everyone from List',
);

for ($i = 1; $i <= BAB_CUSTOM_FIELD_COUNT; $i++) {
    $_bab_pm_l18n["subscriberCustom{$i}"] = "Custom field {$i} name";
}

#-------------------------------------------------------------------------------
#   String support routines...
#-------------------------------------------------------------------------------
register_callback( 'bab_pm_enumerate_strings' , 'l10n.enumerate_strings' );
function bab_pm_enumerate_strings($event , $step = '' , $pre = 0)
{
    global $_bab_pm_l18n;

    $r = array  (
        'owner'     => 'bab_pm',            #   Change to your plugin's name
        'prefix'    => bab_pm_prefix,       #   Its unique string prefix
        'lang'      => 'en-gb',             #   The language of the initial strings.
        'event'     => 'public',            #   public/admin/common = which interface the strings will be loaded into
        'strings'   => $_bab_pm_l18n,       #   The strings themselves.
    );

    return $r;
}

function bab_pm_gTxt($what,$args = array())
{
    global $_bab_pm_l18n, $textarray;

    $key = strtolower( bab_pm_prefix . '-' . $what );

    if (isset($textarray[$key])) {
        $str = $textarray[$key];
    } else {
        $key = strtolower($what);

        if (isset($_bab_pm_l18n[$key])) {
            $str = $_bab_pm_l18n[$key];
        } elseif (isset($_bab_pm_l18n[$what])) {
            $str = $_bab_pm_l18n[$what];
        } elseif (isset($textarray[$key])) {
            $str = $textarray[$key];
        } else {
            $str = $what;
        }
    }

    if (!empty($args)) {
        $str = strtr( $str , $args );
    }

    return $str;
}

#===========================================================================
#   Plugin preferences...
#===========================================================================
global $_bab_pm_prefs;

$_bab_pm_prefs = array(
    'subscribers_per_page'    => array('type' => 'text_input', 'val' => '20', 'position' => 50) ,
    'emails_per_batch'        => array('type' => 'text_input', 'val' => '50', 'position' => 60) ,
    'email_batch_delay'       => array('type' => 'text_input', 'val' => '3', 'position' => 61) ,
    'form_select_prefix'      => array('type' => 'text_input', 'val' => 'newsletter-', 'position' => 70) ,
    'default_unsubscribe_url' => array('type' => 'text_input', 'val' => '', 'position' => 100),
);

for ($i = 1; $i <= BAB_CUSTOM_FIELD_COUNT; $i++) {
    $_bab_pm_prefs["subscriberCustom{$i}"] = array('type' => 'text_input', 'val' => "Custom {$i}:", 'position' => $i + 19);
}

#-------------------------------------------------------------------------------
#   Pref support routines...
#-------------------------------------------------------------------------------
if (txpinterface === 'admin') {
    register_callback('_bab_pm_handle_prefs_pre', 'prefs', 'advanced_prefs', 1);
    register_callback('_bab_pm_handle_prefs_pre', 'prefs', 'advanced_prefs_save', 1);
    register_callback('_bab_pm_handle_prefs_pre', 'postmaster', 'prefs', 1);
}

function _bab_prefix_key($key)
{
    return bab_pm_prefix.'-'.$key;
}

function _bab_pm_install_pref($key, $value, $type, $position = 0)
{
    global $prefs, $textarray, $_bab_pm_l18n;

    $k = _bab_prefix_key($key);

    if (!array_key_exists($k, $prefs)) {
        set_pref($k, $value, bab_pm_prefix, 1, $type, $position);
        $prefs[$k] = $value;
    }

    # Insert the preference strings for non-mlp sites...
    $k = strtolower($k);

    if (!array_key_exists($k , $textarray)) {
        $textarray[$k] = $_bab_pm_l18n[$key];
    }
}

function _bab_pm_remove_prefs()
{
    safe_delete('txp_prefs', "`event`='".bab_pm_prefix."'");
}

function _bab_pm_handle_prefs_pre($event, $step)
{
    global $prefs, $_bab_pm_prefs;

    if (!empty($prefs['plugin_cache_dir'])) {
        $dir = rtrim($prefs['plugin_cache_dir'], DS) . DS;

        # in case it's a relative path
        if (!is_dir($dir)) {
            $dir = rtrim(realpath(txpath.DS.$dir), DS) . DS;
        }

        $filename = $dir.'postmaster'.DS.'overrides.php';

        if (is_file($filename)) {
            # Bring in the preference overrides from the file...
            @include_once( $filename );
        }
    }

    if (version_compare($prefs['version'], '4.0.6', '>=')) {
        foreach ($_bab_pm_prefs as $key => $data) {
            _bab_pm_install_pref($key, $data['val'], $data['type'], $data['position']);
        }
    } else {
        _bab_pm_remove_prefs();
    }
}


function bab_pm_preferences($what)
{
    $lang = array(
        // ---- subscriber-related preferences

        'subscriberFirstName'     => 'First Name:',
        'subscriberLastName'      => 'Last Name:',
        'subscriberEmail'         => 'Email:',
        'subscriberLists'           => 'Lists:',

        // ---- list-related preferences

        'listName'    => 'List Name:',
        'listDescription'         => 'Description:',
        'listAdminEmail'         => 'Admin Email:',
        'listUnsubscribeUrl'           => 'Unsubscribe Url:',
        'listEmailForm'           => 'Form:',
        'listSubjectLine'           => 'Subject Line:',

        // ---- alert text

        'subscriber_add'            => 'Added subscriber.',
        'subscriber_edit'       => 'Updated subscriber information.',
        'subscriber_delete'         => 'Deleted subscriber.',
        'subscribers_delete'            => 'Deleted subscribers.',
        'subscribers_add_to_list'   => 'Selected subscribers added to list',
        'subscribers_remove_from_list'  => 'Selected subscribers removed from list',

        'subscribers_delete_lists'  => 'Deleted selected lists',
        'subscribers_add_all_to_list'   => 'Add everyone to selected lists',
        'subscribers_remove_all_from_list'  => 'Removed everyone from selected lists',

        'list_add'          => 'Added list.',
        'list_edit'     => 'Updated list information.',
        'list_delete'           => 'Deleted list.',
        'lists_delete'          => 'Deleted lists.',
        'uploaded'          => 'Uploaded from file.',
        'prefs_saved'   => 'Preferences saved.',

        // ---- miscellaneous preferences

        'edit_fields_width'           => '440',
        'edit_fields_height'           => '14',
        'zemDoSubscribe_no'           => 'No',
        'unsubscribe_error'           => 'That is not a valid unsubscription. Please contact the list administrator or website owner. ',
        'aggregate_field'           => 'comSubscriberAggregate',

    );

    $result = @$lang[$what];

    if (!$result) {
        global $prefs;

        $key = _bab_prefix_key( $what );
        $result = get_pref($key, '');
    }

    return $result;
}

// ----------------------------------------------------------------------------
// bab_pm_file_upload_form --> should move to the Library
function bab_pm_file_upload_form($label, $pophelp, $step, $id = '')
{
    global $file_max_upload_size;

    if (!$file_max_upload_size || intval($file_max_upload_size) == 0) {
        $file_max_upload_size = 2*(1024*1024);
    }

    $max_file_size = (intval($file_max_upload_size) == 0) ? '' : intval($file_max_upload_size);

    $label_id = (@$label_id) ? $label_id : 'postmaster-upload';

    return '<form method="post" enctype="multipart/form-data" action="index.php">'
        . '<div>'
        . (!empty($max_file_size)? n.hInput('MAX_FILE_SIZE', $max_file_size): '')
        . eInput('postmaster')
        . sInput('import')
        . graf(
            '<label for="'.$label_id.'">'.$label.'</label>'.sp.
                fInput('file', 'thefile', '', 'edit', '', '', '', '', $label_id).sp.
                fInput('submit', '', gTxt('upload'), 'smallerbox')
        )
        . '<br /><input type="checkbox" name="overwrite" /> Overwrite subscribers that already exist'
        . '<br /><input type="checkbox" name="dump_first" /> Empty subscribers list before import'
        . '</div></form>';
        ;
}

// ----------------------------------------------------------------------------
// Import from the old Newsletter Manager plugin
// move to library
function bab_pm_importfromnm()
{
    $step = gps('step');

    echo '<p class=bab_pm_subhed>IMPORT FROM NEWSLETTER MANAGER</P>';
    echo '<fieldset id="bab_pm_importfromnm"><legend><span class="bab_pm_underhed">Import Subscribers</span></legend>';

    $bab_txp_subscribers_table = safe_pfx('txp_subscribers');
    $bab_pm_SubscribersTable = safe_pfx('bab_pm_subscribers');
    $result = safe_query("UPDATE $bab_pm_SubscribersTable SET flag = '' ");
    $subscribers = getRows("select * from $bab_txp_subscribers_table");

    foreach ($subscribers as $subscriber) {
        $subscriberName = $subscriber['name'];
        $subscriberEmail = $subscriber['email'];
        $subscriberCustom1 = $subscriber['nl1'];
        $subscriberCustom2 = $subscriber['nl2'];
        $subscriberCustom3 = $subscriber['nl3'];
        $subscriberCustom4 = $subscriber['nl4'];
        $subscriberCustom5 = $subscriber['nl5'];
        $subscriber_prefs = $subscriber['subscriber_prefs'];
        $oldCatchall = $subscriber['catchall'];
        //insert old subs into new db table

        $md5 = md5(uniqid(rand(),true));
        $strSQL = safe_query("INSERT INTO $bab_pm_SubscribersTable values (NULL,'$subscriberName','$subscriberEmail','default','$subscriberCustom1','$subscriberCustom2','$subscriberCustom3','$subscriberCustom4','$subscriberCustom5','$subscriber_prefs','$oldCatchall','','','','latest','','$md5')");
    }

    echo 'Check out your new subscribers <a href="?event=postmaster&step=subscriberlist">here</a>.<div class=bab_pm_alerts>NOTE: The old Newsletter Manager tables will remain in your database until you remove them.</div>';
    echo '</fieldset>';
}

// ---- MAIL CODA ------------------------------------------------
function bab_pm_mail_coda() // this is the coda, after mailing is complete
{
    echo '<div class=bab_pm_alerts>
<img src="/images/percentImage.png" alt="complete" style="background-position: 0% 0%;" class="pm_prog_bar" /><br />
<p style="padding-top:10px;text-align:center;">Your mailing is complete. You may now close this window.</p>
<p style="padding-top:10px;text-align:center;"><a href="?event=article">Return to Content > Write</a></p>
</div>';
    exit;
}

// ---- CREATE TABLES ------------------
// ---- This function creates two tables:
// ---- the postMasterPrefs table (which handles the admin preferences)
// ---- the subscribers table (which holds all of your subscriber information)
function bab_pm_createTables()
{
    global $txpcfg, $bab_pm_PrefsTable , $bab_pm_SubscribersTable, $bab_pm_mapTable, $DB;

    //function to create database
    $version = mysqli_get_server_info($DB->link);
    $dbcharset = "'".$txpcfg['dbcharset']."'";

    //Use "ENGINE" if version of MySQL > (4.0.18 or 4.1.2)
    $tabletype = ( intval($version[0]) >= 5 || preg_match('#^4\.(0\.[2-9]|(1[89]))|(1\.[2-9])#',$version))
        ? " ENGINE=MyISAM "
        : " TYPE=MyISAM ";

    // On 4.1 or greater use utf8-tables
    if (isset($dbcharset) && (intval($version[0]) >= 5 || preg_match('#^4\.[1-9]#',$version))) {
        $tabletype .= " CHARACTER SET = $dbcharset ";

        if (isset($dbcollate))
            $tabletype .= " COLLATE $dbcollate ";
        mysqli_query($DB->link, "SET NAMES ".$dbcharset);
    }

    $create_sql[] = safe_query("CREATE TABLE IF NOT EXISTS $bab_pm_PrefsTable (
        `listID` int(4) NOT NULL auto_increment,
        `listName` varchar(100) NOT NULL default '',
        `listDescription` longtext NULL,
        `listAdminEmail` varchar(100) NOT NULL default '',
        `listUnsubscribeUrl` varchar(100) NOT NULL default '',
        `listEmailForm` varchar(100) NOT NULL default '',
        `listSubjectLine` varchar(128) NOT NULL default '',
        `catchall` longtext NULL,
        PRIMARY KEY  (`listID`)
    ) $tabletype ");

    $custom_fields = '';

    for ($i = 1; $i <= BAB_CUSTOM_FIELD_COUNT; $i++) {
        $custom_fields .= "`subscriberCustom{$i}` longtext NULL," . n;
    }

    $create_sql[] = safe_query("CREATE TABLE IF NOT EXISTS $bab_pm_SubscribersTable (
        `subscriberID` int(4) NOT NULL auto_increment,
        `subscriberFirstName` varchar(30) NOT NULL default '',
        `subscriberLastName` varchar(30) NOT NULL default '',
        `subscriberEmail` varchar(100) NOT NULL default '',
        {$custom_fields}
        `subscriberCatchall` longtext NULL,
        `flag` varchar(100) NOT NULL default '',
        `unsubscribeID` varchar(100) NOT NULL default '',
        PRIMARY KEY  (`subscriberID`),
        UNIQUE (subscriberEmail)
    ) $tabletype ");

    $bab_pm_subscribers_list = safe_pfx('bab_pm_subscribers_list');
    $create_sql[] = safe_query("CREATE TABLE IF NOT EXISTS $bab_pm_subscribers_list (
        `id` int(4) NOT NULL auto_increment,
        `list_id` int(4) NOT NULL,
        `subscriber_id` int(4) NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE (`list_id`, `subscriber_id`)
    ) $tabletype ");

    $create_sql[] = safe_query("ALTER TABLE $bab_pm_subscribers_list ADD INDEX `subscriber_id` ( `subscriber_id` )");

//--- insert initial row in prefs table -------------------------------

    $create_sql[] = safe_query("INSERT INTO $bab_pm_PrefsTable values ('1','default','All subscribers','','','','Notification: A new article has been posted at &lt;txp:site_url /&gt;','')");

//--- insert initial row in subs table -------------------------------

    $md5 = md5(uniqid(rand(),true));
    $create_sql[] = safe_query("INSERT INTO $bab_pm_SubscribersTable (subscriberFirstName, subscriberLastName, subscriberEmail, subscriberCustom1, subscriberCustom10, unsubscribeID) values ('Test','User','test@test','custom1','custom10','$md5')");

    safe_insert('bab_pm_subscribers_list', "list_id=1, subscriber_id=1");

    return;
}

function bab_pm_addCustomFields($columns = null)
{
    global $txpcfg, $bab_pm_PrefsTable , $bab_pm_SubscribersTable, $bab_pm_mapTable;

    for ($i = 1; $i <= BAB_CUSTOM_FIELD_COUNT; $i++) {
        $n = "subscriberCustom{$i}";

        if (empty($columns) || (is_array($columns) && !in_array($n, $columns))) {
            safe_query("ALTER TABLE {$bab_pm_SubscribersTable} ADD COLUMN `{$n}` longtext NULL");
        }
    }
}


function bab_pm_create_subscribers_list()
{
    global $txpcfg, $bab_pm_PrefsTable , $bab_pm_SubscribersTable, $bab_pm_mapTable, $DB;

    $lists_table = @getThings('describe `'.PFX.'bab_pm_subscribers_list`');

    if ($lists_table) {
        return;
    }

    //function to create database
    $version = mysqli_get_server_info($DB->link);
    $dbcharset = "'".$txpcfg['dbcharset']."'";

    //Use "ENGINE" if version of MySQL > (4.0.18 or 4.1.2)
    $tabletype = ( intval($version[0]) >= 5 || preg_match('#^4\.(0\.[2-9]|(1[89]))|(1\.[2-9])#',$version))
        ? " ENGINE=MyISAM "
        : " TYPE=MyISAM ";

    // On 4.1 or greater use utf8-tables
    if ( isset($dbcharset) && (intval($version[0]) >= 5 || preg_match('#^4\.[1-9]#',$version))) {
        $tabletype .= " CHARACTER SET = $dbcharset ";

        if (isset($dbcollate))
            $tabletype .= " COLLATE $dbcollate ";
        mysqli_query($DB->link, "SET NAMES ".$dbcharset);
    }

    $bab_pm_subscribers_list = safe_pfx('bab_pm_subscribers_list');

    $sql[] = safe_query("CREATE TABLE IF NOT EXISTS $bab_pm_subscribers_list (
        `id` int(4) NOT NULL auto_increment,
        `list_id` int(4) NOT NULL,
        `subscriber_id` int(4) NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE (`list_id`, `subscriber_id`)
    ) $tabletype ");

    // get lists
    $lists = safe_rows('listID, listName', 'bab_pm_list_prefs', '1=1');

    // loop over subscribers
    $rs = safe_rows_start('subscriberID, subscriberLists', 'bab_pm_subscribers', '1=1');

    if ($rs) {
        while ($row = nextRow($rs)) {
            extract($row);

            foreach($lists as $list) {
                extract($list);

                if (stripos($subscriberLists, $listName) !== false) {
                    safe_insert('bab_pm_subscribers_list',
                        "list_id = $listID, subscriber_id = $subscriberID");
                }
            }
        }
    }
}

// ---- BAB_PM_UNSUBSCRIBE ------------------------------------------------.

function bab_pm_unsubscribe()
{
    $unsubscribeID = gps('uid');
    $unsubscribeID = doSlash($unsubscribeID);

    if (safe_delete("bab_pm_subscribers", "unsubscribeID='$unsubscribeID'")) {
        safe_delete("bab_pm_subscribers_list", "subscriber_id = '$unsubscribeID'");
        return '';
    }

    return bab_pm_preferences('unsubscribe_error');
}


// -------------------------------------------------------------
function subscriberlist_searching_form($crit, $method)
{
    global $prefs;

    $methods =  array(
        'name' => gTxt('Subscriber Name'),
        'email' => gTxt('Subscriber Email'),
        'lists' => gTxt('Subscriber List')
    );

    $page_url = page_url(array());

    for ($i = 1; $i <= 10; $i++) {
        $field = 'subscriberCustom' . $i;
        $key = 'bab_pm-' . $field;

        if (!empty($prefs[$key])) {
            $methods[$field] = $prefs[$key];
        }
    }

    $selection = selectInput('method', $methods, $method);

    $search_form = <<<search_form
<form action="$page_url" method="POST" id="subscriber_edit_form" style="text-align:center;padding-bottom:10px;">
Search by $selection : <input type="text" name="crit" value="$crit" class="edit" size="15">
<input type="submit" value="Go" class="smallerbox">
</form>
search_form;

    return $search_form;
/*  return
    form(
        graf(gTxt('Search by').sp.selectInput('method',$methods,$method). ' : ' .
            fInput('text','crit',$crit,'edit','','','15').
            eInput("postmaster").sInput('subscribers').sp.
            fInput("submit","search",gTxt('go'),"smallerbox"),' align="center"')
    );*/
}

// -------------------------------------------------------------
function listlist_searching_form($crit, $method)
{
    $methods =  array(
        'name'        => gTxt('List Name'),
        'admin email' => gTxt('Admin Email'),
    );

    $atts['type'] = 'request_uri';
    $page_url = page_url($atts);
    $selection = selectInput('method', $methods, $method);

$search_form = <<<search_form
<form action="$page_url" method="POST" id="subscriber_edit_form" style="text-align:center;padding-bottom:10px;">
Search by $selection : <input type="text" name="crit" value="$crit" class="edit" size="15">
<input type="submit" value="Go" class="smallerbox">
</form>
search_form;

    return $search_form;
    /*
    return
    form(
        graf(gTxt('Search by').sp.selectInput('method',$methods,$method). ' : ' .
            fInput('text','crit',$crit,'edit','','','15').
            eInput("postmaster").sInput('lists').sp.
            fInput("submit","search",gTxt('go'),"smallerbox"),' align="center"')
    ); */
}

// -------------------------------------------------------------
function subscriberlist_nav_form($page, $numPages, $sort, $dir = '', $crit = '', $method = '')
{
    $nav[] = ($page > 1)
        ?   bab_pm_PrevNextLink("postmaster",$page-1,gTxt('prev'),'prev',$sort, $dir, $crit, $method)
        :   '';
    $nav[] = sp.small($page. '/'.$numPages).sp;

    $nav[] = ($page != $numPages)
        ?   bab_pm_PrevNextLink("postmaster",$page+1,gTxt('next'),'next',$sort, $dir, $crit, $method)
        :   '';
    if ($nav) {
        return graf(join('',$nav),' align="center"');
    }
}

// -------------------------------------------------------------
function subscriberlist_multiedit_form()
{
    return event_multiedit_form('postmaster','','','','','','');
}

// -------------------------------------------------------------
function subscriberlist_multi_edit()
{
    if (ps('selected') and !has_privs('postmaster')) {
        $ids = array();

        if (has_privs('postmaster')) {
            foreach (ps('selected') as $subscriberID) {
                $subscriber = safe_field('subscriberID', 'bab_pm_subscribers', "ID='".doSlash($id)."'");
            }

            $_POST['selected'] = $ids;
        }

        $deleted = event_multi_edit('bab_pm_subscribers','subscriberID');

        if (!empty($deleted)){
            $method = ps('method');

            return bab_pm_subscriberlist(messenger('postmaster',$deleted,(($method == 'delete')?'deleted':'modified')));
        }

        return bab_pm_subscriberlist();
    }
}

// ---- copy of column_head function to allow for different $step value

function bab_pm_column_head($value, $sort = '', $current_event = '', $islink = '', $dir = '')
{
    $o = '<th class="small"><strong>';

    if ($islink) {
        $o.= '<a href="index.php';
        $o.= ($sort) ? "?sort=$sort":'';
        $o.= ($dir) ? a."dir=$dir":'';
        $o.= ($current_event) ? a."event=$current_event":'';
        $o.= a.'step=subscribers">';
    }

    $o .= gTxt($value);

    if ($islink) {
        $o .= "</a>";
    }

    $o .= '</strong></th>';

    return $o;
}

// ---- copy of column_head function to allow for different $step value

function bab_pm_list_column_head($value, $sort = '', $current_event = '', $islink = '', $dir = '')
{
    $o = '<th class="small"><strong>';

    if ($islink) {
        $o.= '<a href="index.php';
        $o.= ($sort) ? "?sort=$sort":'';
        $o.= ($dir) ? a."dir=$dir":'';
        $o.= ($current_event) ? a."event=$current_event":'';
        $o.= a.'step=lists">';
    }

    $o .= gTxt($value);
    if ($islink) {
        $o .= "</a>";
    }
    $o .= '</strong></th>';
    return $o;
}

// ---- copy of PrevNextLink function to allow for different $step value
function bab_pm_PrevNextLink($event, $topage, $label, $type, $sort = '', $dir = '', $crit = '', $method = '')
{
    return join('', array(
        '<a href="?event='.$event.a.'step=subscribers'.a.'page='.$topage,
        ($sort) ? a.'sort='.$sort : '',
        ($dir) ? a.'dir='.$dir : '',
        ($crit) ? a.'crit='.$crit : '',
        ($method) ? a.'method='.$method : '',
        '" class="navlink">',
        ($type=="prev") ? '&#8249;'.sp.$label : $label.sp.'&#8250;',
        '</a>'
        ));
}


//custom data tag

function bab_pm_data($atts)
{
    global $row, $rs, $thisarticle;

    extract(lAtts(array(
        'display'    => 'Body',
        'strip_html' => 'no',
    ),$atts));

    global $$display; // contents of $display becomes the variable name

    // article data
    if (is_array($rs)) {
        extract($rs);
    } elseif (is_array($thisarticle)) {
        extract($thisarticle);
    }

    // list data
    if (is_array($row)) {
        extract($row);
    }

/* need to update documentation, because now the options for display="" are the actual column names (in order to make $$display work) */

// ---- article-related

    if ($display == 'link') {
        $link = "<txp:permlink />";
        $parsed_link = parse($link);

        return $parsed_link;
    }

    if ($display == 'Body_html') {
        if (!$Body_html) {
            return;
        } else {
            if ($strip_html == 'yes') {
                $Body_html = strip_tags(deGlyph($Body_html));
            }

            return $Body_html;
        }
    }

    if ($display == 'Excerpt_html') {
        if (!$Excerpt_html) {
            return;
        } else {
            if ($strip_html == 'yes') {
                $Excerpt_html = strip_tags(deGlyph($Excerpt_html));
            }

            return $Excerpt_html;
        }
    } else {
        return $$display;
    }
}


// ------------------------------------------------------------
function bab_pm_mime($atts)
{
    // If you're coming here, that means it's HTML -- no need to check.
    global $headers, $mime_boundary, $listAdminEmail;

    // Determine which mime type is required.
    extract(lAtts(array(
        'type' => 'text',
    ),$atts));

    // Build mimes - trailing blank line is necessary.
    $text_mime = <<<text_mime
--$mime_boundary
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: 8bit

text_mime;

    $html_mime = <<<html_mime
--$mime_boundary
Content-Type: text/html; charset=UTF-8
Content-Transfer-Encoding: 8bit

html_mime;

    $end_mime = <<<end_mime
--$mime_boundary--
end_mime;

    // Overwrite default content-type header.
    $headers['Content-Type'] = 'multipart/alternative; boundary="'.$mime_boundary.'"';

    if ($type === 'text') {
        return $text_mime;
    }

    if ($type === 'html') {
        return $html_mime;
    }

    if ($type === 'end') {
        return $end_mime;
    }

    return;
}

// ------------------------------------------------------------
function bab_pm_unsubscribeLink($atts, $thing = '')
{
    global $bab_pm_unsubscribeLink, $prefs;

    extract(lAtts(array(
        'type' => 'text',
    ), $atts));

    $default_url = $prefs[_bab_prefix_key('default_unsubscribe_url')];

    $url = empty($bab_pm_unsubscribeLink) ? $url = $default_url : $bab_pm_unsubscribeLink;

    if ($type == 'html') {
        $url = $bab_pm_unsubscribeLink = "<a href=\"$url\">$url</a>";
    }

    return $url;
}

function bab_unsubscribe_url($atts, $thing='')
{
    return bab_pm_unsubscribeLink($atts,$thing);
}

//-------------------------------------------------------------
function bab_pm_upgrade_db()
{
    global $bab_pm_SubscribersTable;

//test for existence of first and last name columns
    // $sql = "SHOW COLUMNS FROM {$bab_pm_SubscribersTable} LIKE '%name%'";
    //
    // $rs = safe_rows($sql);
    // if(numRows($rs) < 2) {
        //we'll assume that 2+ name columns means the upgrade's already run
            $sql = <<<END
                ALTER TABLE {$bab_pm_SubscribersTable} ADD COLUMN subscriberLastName varchar(30) NOT NULL default '' AFTER subscriberID,
                ADD COLUMN subscriberFirstName varchar(30) NOT NULL default '' AFTER subscriberID
END;

        $rs = safe_query($sql);
    // }

}

// ===========================

function deGlyph($text)
{
    $glyphs = array (
        '&#8217;',   //  single closing
        '&#8216;',  //  single opening
        '&#8220;',                 //  double closing
        '&#8222;',              //  double opening
        '&#8230;',              //  ellipsis
        '&#8212;',                   //  em dash
        '&#8211;',             //  en dash
        '&#215;',             //  dimension sign
        '&#8482;',          //  trademark
        '&#174;',               //  registered
        '&#169;',             //  copyright
        '&#160;',             //  non-breaking space numeric
        '&#nbsp;',             //  non-breaking space named
        '&#38;',             //  ampersand numeric
        '&amp;'             //  ampersand named
    );

    $deGlyphs = array (
        "'",           //  single closing
        "'",           //  single opening
        '"',           //  double closing
        '"',           //  double opening
        '...',         //  ellipsis
        ' -- ',        //  em dash
        ' - ',         //  en dash
                ' x ',         //  dimension sign
        'T',          //  trademark
        'R',          //  registered
        '(c)',        //  copyright
        ' ',          //  non-breaking space numeric
        ' ',          //  non-breaking space named
        '&',          //  ampersand numeric
        '&'           //  ampersand named
    );

    $text = str_replace($glyphs, $deGlyphs, $text);
//  return $text;
// changed to try and remove white space on deglyphed text
    return trim($text);
}

# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
<p><style>
.note {
    border:1px solid gray;padding:10px;background-color:#f5f5f5;color:red;font-size:smaller;margin-bottom:20px;margin-top:20px;
}
</style>
<p>Whether this is your first time using Postmaster, or you?ve come to solve a problem, your first step is to follow the tutorial (for people resolving an issue, this provides a baseline to work from):</p></p>

<p>First things first:</p>

<ul>
    <li>Is Postmaster successfully installed and set to &#8220;Active&#8221;?</li>
    <li>Is the Postmaster Library successfully installed and set to &#8220;Active&#8221;?</li>
    <li>Do you have the Zem_Contact_Reborn plugin installed and set to &#8220;Active&#8221;? Remember that Zem_Contact_Reborn requires a separate language plugin to work properly.</li>
    <li>Is your browser set to accept Javascript?</li>
</ul>

<p>All set? OK, then. First things first&#8212;Click on the Postmaster tab under the Extensions tab in your Textpattern Admin. You will see three sub-tabs: Subscribers, Lists and Import/Export.</p>

<div class="note"><span class="caps">NOTE</span>: The first time you click the Postmaster tab, the plugin automatically creates the two database tables needed to store information (one for lists, and one for subscribers). It will also enter a &#8220;default&#8221; list and a &#8220;test&#8221; subscriber to each table.</div>

<p>Click on the Lists sub-tab for now. This opens a &#8220;list of lists&#8221; page, with the total number of lists displayed at the top, a link to &#8220;Add a List,&#8221; a search form and a table displaying a list of all lists you have entered.</p>

<p>You can re-order the list of lists using the &#8220;Name&#8221; or &#8220;Admin Email&#8221; column headers. There&#8217;s a checkbox next to each list and a separate &#8220;Check All&#8221; box (you can select some or all lists and click the &#8220;Delete&#8221; button to delete). Currently there is only one list, &#8220;default,&#8221; which Postmaster entered for you. You&#8217;ll have to make some adjustments to &#8220;default&#8221; before we can send any mail so click the list name (&#8220;default&#8221;).</p>

<p>That brings up the Editing List page, which displays <i>all</i> the fields of data for each list: List Name, List Description, Admin Email, Unsubscribe <span class="caps">URL</span>, List Form and Subject Line.</p>

<p>You may change or edit any of these fields at any time, and then click the &#8220;Update List Information&#8221; button at the bottom to save your changes. For now, leave everything alone except the Admin Email field, in which you should enter a real email address. After you&#8217;ve entered the email address, click the &#8220;Update List Information&#8221; button.</p>

<p>A list isn&#8217;t really a list without a subscriber&#8212;so click the Subscribers sub-tab now. This opens a &#8220;list of subscribers&#8221; page much like the &#8220;list of lists&#8221; page. There is only one subscriber listed, &#8220;test,&#8221; with a faulty email address entered. Click on the subscriber name to update your subscriber&#8217;s information.</p>

<p>That brings up an Editing Subscriber page, much like the &#8220;Editing List&#8221; page you already saw. This page has quite a few more fields: Subscriber Name, Subscriber Email, Subscriber Lists and Subscriber Custom 1 through Subscriber Custom 10.</p>

<div class="note"><span class="caps">NOTE</span>: The Subscriber Lists field should contain the word &#8220;default&#8221;&#8212;that means subscriber &#8220;Test&#8221; is a member of list &#8220;default.&#8221;</div>

<p>You may change or edit any of these fields at any time, and then click <span class="caps">UPDATE</span> <span class="caps">SUBSCRIBER</span> <span class="caps">INFORMATION</span> at the bottom to save your changes. For now, leave everything alone except the <span class="caps">EMAIL</span>, in which you should enter a real email address. After you&#8217;ve entered the email address, click <span class="caps">UPDATE</span> <span class="caps">SUBSCRIBER</span> <span class="caps">INFORMATION</span>.</p>

<p>Now that you have an updated list and subscriber, it&#8217;s time to mail your first email!</p>

<p>Click on the <span class="caps">WRITE</span> tab in your Textpattern Admin. Write a bit of content&#8212;give it title &#8220;newsletter test&#8221; and in the body, write &#8220;Hey diddy diddy, there&#8217;s a kiddy in the middle.&#8221; Assign the new article to a section that won&#8217;t go public, and click <span class="caps">PUBLISH</span>.</p>

<p>Once the page refreshes, you&#8217;ll see a new module underneath the <span class="caps">SAVE</span> button, called &#8220;Email to subscribers?&#8221; There is a dropdown menu and a set of radio buttons. Use the dropdown menu to select a list (currently you only have one choice) and fill a radio button other than &#8220;No&#8221; if you&#8217;d like to send a mail (&#8220;Test&#8221; sends mail to the admin email address of the selected list; &#8220;Yes&#8221; sends mail to the entire selected list).</p>

<p>Once you&#8217;ve selected your list, and the correct radio button (you selected <span class="caps">TEST</span> for your first one, right?), click the <span class="caps">SAVE</span> button again. You will be taken to the Bulk Mail screen, which shows your mail status. Wait there until you receive the all clear (if you selected Test, this is immediate).</p>

<p>Go to the inbox of whatever email you entered&#8212;you should have received an email called &#8220;Notification: &#8230;&#8221; Congratulations!</p>

<div class="note"><span class="caps">NOTE</span>: if you have any problems up to this point, you need to come to the <txp:bab_link id="26" linktext="support forum thread" class="support_forum_thread" /> for help.</div>

<p>Lots more documentation is available in the <a href="http://www.benbruce.com/postmanual">Postmanual</a>.</p>
# --- END PLUGIN HELP ---
-->
<?php
}
?>
