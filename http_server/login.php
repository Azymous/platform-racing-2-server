<?php

header("Content-type: text/plain");

require_once GEN_HTTP_FNS;
require_once HTTP_FNS . '/rand_crypt/Encryptor.php';
require_once QUERIES_DIR . '/exp_today.php';
require_once QUERIES_DIR . '/friends.php';
require_once QUERIES_DIR . '/ignored.php';
require_once QUERIES_DIR . '/messages.php';
require_once QUERIES_DIR . '/mod_actions.php';
require_once QUERIES_DIR . '/mod_power.php';
require_once QUERIES_DIR . '/part_awards.php';
require_once QUERIES_DIR . '/rank_tokens.php';
require_once QUERIES_DIR . '/rank_token_rentals.php';
require_once QUERIES_DIR . '/recent_logins.php';
require_once QUERIES_DIR . '/servers.php';

$encrypted_login = default_post('i', '');
$version = default_post('version', '');
$in_token = find('token');
$allowed_versions = array('22-apr-2019-v155');
$guest_login = false;
$token_login = false;
$has_email = false;
$has_ant = false;
$rt_available = 0;
$rt_used = 0;
$guild_owner = 0;
$emblem = '';
$guild_name = '';
$friends = array();
$ignored = array();

// get the user's IP and run it through an IP info API
$ip = get_ip();
$ip_info = json_decode(file_get_contents('https://tools.keycdn.com/geo.json?host=' . $ip));
$country_code = ($ip_info !== false && !empty($ip_info)) ? $ip_info->data->geo->country_code : '?';
$country_code = '?';

$ret = new stdClass();
$ret->success = false;

try {
    // sanity check: POST?
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method.");
    } // sanity check: was data received?
    if (!isset($encrypted_login)) {
        throw new Exception('Login data not recieved.');
    } // sanity check: is it an allowed version?
    if (array_search($version, $allowed_versions) === false) {
        throw new Exception('PR2 has recently been updated. Please refresh the page to download the latest version.');
    }

    // correct referrer?
    if (strpos($ip, $BLS_IP_PREFIX) !== 0) {
        require_trusted_ref('log in');
    }

    // rate limiting
    rate_limit('login-'.$ip, 5, 2, 'Please wait at least 5 seconds before trying to log in again.');
    rate_limit('login-'.$ip, 60, 10, 'Only 10 logins per minute per IP are accepted.');

    // decrypt login data
    $encryptor = new \pr2\http\Encryptor();
    $encryptor->setKey($LOGIN_KEY);
    $str_login = $encryptor->decrypt($encrypted_login, $LOGIN_IV);
    $login = json_decode($str_login);
    $login->ip = $ip;
    $user_name = $login->user_name;
    $user_pass = $login->user_pass;
    $version2 = $login->version;
    $server_id = $login->server->server_id;
    $server_port = $login->server->port;
    $server_address = $login->server->address;
    $remember = $login->remember;

    // more sanity checks
    if (array_search($version2, $allowed_versions) === false) {
        $e = "PR2 has recently been updated. Please refresh the page to download the latest version. $version2";
        throw new Exception($e);
    }
    if ((is_empty($in_token) === true && is_empty($user_name) === true) || strpos($user_name, '`') !== false) {
        throw new Exception('Invalid user name entered.');
    }

    // connect
    $pdo = pdo_connect();

    // get the server they're connecting to
    $server = server_select($pdo, $server_id);

    // guest login
    if (strtolower(trim($login->user_name)) === 'guest') {
        $guest_login = true;
        $user = user_select_guest($pdo);
        check_if_banned($pdo, $user->user_id, $ip);
    } // account login
    else {
        // token login
        if (isset($in_token) && $login->user_name === '' && $login->user_pass === '') {
            $token_login = true;
            $token = $in_token;
            $user_id = token_login($pdo);
            $user = user_select($pdo, $user_id);
        } // or password login
        else {
            $user = pass_login($pdo, $user_name, $user_pass);
        }

        // see if they're trying to log into a guest
        if ((int) $user->power === 0 && $guest_login === false && $token_login === false) {
            $e = 'Direct guest account logins are not allowed. Please instead click "Play as Guest" on the main menu.';
            throw new Exception($e);
        }
    }

    // generate a login token for future requests
    $token = get_login_token($user->user_id);
    token_insert($pdo, $user->user_id, $token);
    if ($remember === true && $guest_login === false) {
        $token_expire = time() + 2592000; // one month
        setcookie('token', $token, $token_expire, '/', $_SERVER['SERVER_NAME'], false, true);
    } else {
        setcookie('token', '', time() - 3600, '/', $_SERVER['SERVER_NAME'], false, true);
    }

    // create variables from user data in db
    $user_id = (int) $user->user_id;
    $user_name = $user->name;
    $group = (int) $user->power;

    // sanity check: is the entered name and the one retrieved from the database identical?
    // this won't be triggered unless some real funny business is going on
    if (($token_login === false || !is_empty($login->user_name)) &&
        strtolower($login->user_name) !== strtolower($user_name) &&
        $guest_login === false
    ) {
        throw new Exception('The names don\'t match. If this error persists, contact a member of the PR2 Staff Team.');
    }

    // sanity check: is it a valid name?
    if ($token_login === false) {
        if (strlen(trim($login->user_name)) < 2) {
            throw new Exception('Your name must be at least 2 characters long.');
        }
        if (strlen(trim($login->user_name)) > 20) {
            throw new Exception('Your name cannot be more than 20 characters long.');
        }
    }

    // sanity check: if a guild server, is the user in the guild?
    if ((int) $server->guild_id !== 0 && (int) $user->guild !== (int) $server->guild_id && $group !== 3) {
        throw new Exception('You must be a member of this guild to join this server.');
    }

    // if a mod, get their trial mod status
    if ($group === 2) {
        $unpub = (bool) (int) mod_power_select($pdo, $user_id, true)->can_unpublish_level;
        $user->trial_mod = !$unpub;
    }

    // get their pr2 and epic_upgrades info
    $stats = pr2_select($pdo, $user_id, true);
    if ($stats === false) {
        pr2_insert($pdo, $user_id);
        message_send_welcome($pdo, $user_name, $user_id);
    }
    $epic_upgrades = epic_upgrades_select($pdo, $user_id, true);

    // check if they own rank tokens
    $rank_tokens = rank_token_select($pdo, $user_id);
    if (!empty($rank_tokens)) {
        $rt_available = $rank_tokens->available_tokens;
        $rt_used = $rank_tokens->used_tokens;
    }

    // check if they're renting rank tokens
    $rt_rented = rank_token_rentals_count($pdo, $user->user_id, $user->guild);

    // sanity check: do they have more than 5 permanent rank tokens?
    if ($rt_available > 5) {
        throw new Exception("Too many rank tokens. Please use a different account.");
    }

    // sanity check: are they renting more than 21 guild tokens?
    if ($rt_rented > 21) {
        throw new Exception('Too many guild tokens. Please use a different account.');
    }

    // sanity check: are more tokens used than available?
    $rt_available = $rt_available + $rt_rented;
    if ($rt_available < $rt_used) {
        $rt_used = $rt_available;
    }

    // sanity check: is the user's rank 100+?
    if (((int) $stats->rank + $rt_used >= 100) && $user_id !== FRED) {
        throw new Exception('Your rank is too high. Please choose a different account.');
    }

    // record moderator login
    if ($group > 1 || in_array($user_id, $special_ids)) {
        mod_action_insert($pdo, $user_id, "$user_name logged into $server->server_name from $ip", $user_id, $ip);
    }

    // part arrays
    $hat_array = explode(',', $stats->hat_array);
    $head_array = explode(',', $stats->head_array);
    $body_array = explode(',', $stats->body_array);
    $feet_array = explode(',', $stats->feet_array);

    // check if parts need to be awarded
    $pending_awards = part_awards_select_by_user($pdo, $user_id);
    $stats = award_special_parts($stats, $group, $pending_awards);

    // select their friends list
    $friends_result = friends_select($pdo, $user_id);
    foreach ($friends_result as $fr) {
        $friends[] = $fr->friend_id;
    }

    // select their ignored list
    $ignored_result = ignored_select_list($pdo, $user_id);
    foreach ($ignored_result as $ir) {
        $ignored[] = $ir->ignore_id;
    }

    // get their EXP gained today
    $exp_today_id = exp_today_select($pdo, 'id-'.$user_id);
    $exp_today_ip = exp_today_select($pdo, 'ip-'.$ip);
    $exp_today = max($exp_today_id, $exp_today_ip);

    // check if they have an email set
    $has_email = !is_empty($user->email) && strlen($user->email) > 0 ? true : false; // email set?
    $has_ant = array_search(20, $head_array) !== false ? true : false; // kong account login perk

    // determine if in a guild and if the guild owner
    if ((int) $user->guild !== 0) {
        $guild = guild_select($pdo, $user->guild);
        if ((int) $guild->owner_id === $user_id) {
            $guild_owner = 1;
        }
        $emblem = $guild->emblem;
        $guild_name = $guild->guild_name;
    }

    // get their most recent PM id
    $last_recv_id = messages_select_most_recent($pdo, $user_id);

    // update their status
    $status = "Playing on $server->server_name";
    user_update_status($pdo, $user_id, $status, $server_id);

    // update their IP and record the recent login
    user_update_ip($pdo, $user_id, $ip);
    recent_logins_insert($pdo, $user_id, $ip, $country_code);

    // join the part arrays to send to the server
    $stats->hat_array = join(',', $hat_array);
    $stats->head_array = join(',', $head_array);
    $stats->body_array = join(',', $body_array);
    $stats->feet_array = join(',', $feet_array);

    // send this info to the socket server
    $send = new stdClass();
    $send->login = $login;
    $send->user = $user;
    $send->stats = $stats;
    $send->friends = $friends;
    $send->ignored = $ignored;
    $send->rt_used = $rt_used;
    $send->rt_available = $rt_available;
    $send->exp_today = $exp_today;
    $send->status = $status;
    $send->server = $server;
    $send->epic_upgrades = $epic_upgrades;

    $str = "register_login`" . json_encode($send);
    talk_to_server($server_address, $server_port, $server->salt, $str, false, false);

    // tell the world
    $ret->success = true;
    $ret->token = $token;
    $ret->email = $has_email;
    $ret->ant = $has_ant;
    $ret->time = time();
    $ret->lastRead = $user->read_message_id;
    $ret->lastRecv = $last_recv_id;
    $ret->guild = $user->guild;
    $ret->guildOwner = $guild_owner;
    $ret->guildName = $guild_name;
    $ret->emblem = $emblem;
    $ret->userId = $user_id;
} catch (Exception $e) {
    $ret->error = $e->getMessage();
} finally {
    die(json_encode($ret));
}
