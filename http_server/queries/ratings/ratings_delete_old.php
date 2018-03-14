<?php

function ratings_delete_old($pdo)
{
	$result = $pdo->exec('
        DELETE FROM pr2_ratings
        WHERE time < UNIX_TIMESTAMP(NOW() - INTERVAL 7 DAY)
    ');

    if ($result === false) {
        throw new Exception('could not delete old ratings');
    }

    return $result;
}
