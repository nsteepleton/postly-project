<?php

include "config.php";

/*
 * @return handle to database connection
 */
function db_connect($host, $port, $db, $user, $pw) {
    $dbh = pg_connect('host='.$host.' port='.$port.' dbname='
        .$db.' user='.$user.' password='.$pw) or die('db connection failed');
    return $dbh;
}

/*
 * Close database connection
 */
function close_db_connection($dbh) {
    pg_close($dbh);
}

/*
 * Login if user and password match
 * Return associative array of the form:
 * array(
 *		'status' =>  (1 for success and 0 for failure)
 *		'userID' => '[USER ID]'
 * )
 */
function login($dbh, $user, $pw) {
    $query = <<<QUERY
SELECT * FROM Users
WHERE username=$1 AND password=$2;
QUERY;
    $result = pg_query_params($dbh, $query, array($user, $pw));
    if (!$result or pg_num_rows($result) == 0) {
        return array( 'status' => 0, 'userID' => null );
    }
    else {
        $row = pg_fetch_array($result);
        return array( 'status' => 1,
                      'userID' => $row['username']
                    );
    }
}

/*
 * Register user with given password hash
 * Return associative array of the form:
 * array(
 *		'status' =>   (1 for success and 0 for failure)
 *		'userID' => '[USER ID]'
 * )
 */
function register($dbh, $user, $pw) {
    $query = <<<QUERY
INSERT INTO Users
VALUES ($1, $2);
QUERY;
    $result = pg_query_params($dbh, $query, array($user, $pw));
    if (!$result) {
        return array( 'status' => 0, 'userID' => null );
    }
    else {
        return array( 'status' => 1, 'userID' => $user );
    };
}

/*
 * Submit post for given user
 * Return associative array of the form:
 * array(
 *		'status' =>   (1 for success and 0 for failure)
 * )
 */
function post_post($dbh, $title, $msg, $me) {
    $query = <<<QUERY
SELECT MAX(post_id)
FROM Post;
QUERY;
    $result = pg_query($dbh, $query);
    if (!$result or pg_num_rows($result) == 0) {
        return array( 'status' => 0 );
    }
    else {
        $max_row = pg_fetch_array($result);
        $max_id = ($max_row['max'] == null) ? 0 : $max_row['max'];
    }
    $query = <<<QUERY
INSERT INTO Post
VALUES ($4, now(), $3, $1, $2);
QUERY;
    $result = pg_query_params($dbh, $query, array($title, $msg,
                                                  $me, $max_id + 1));
    return array( 'status' => (!$result) ? 0 : 1 );
}

/*
 * Get timeline of $count most recent posts that were written before
 * timestamp $start For a user $user, the timeline should include all posts.
 * Order by time of the post (going backward in time), and break ties
 * by sorting by the username alphabetically
 * Return associative array of the form:
 * array(
 *		'status' => (1 for success and 0 for failure)
 *		'posts' => [ (Array of post objects) ]
 * )
 * Each post should be of the form:
 * array(
 *              'pID' => (INTEGER)
 *              'username' => (USERNAME)
 *              'title' => (TITLE OF POST)
 *              'content' => (CONTENT OF POST)
 *              'time' => (UNIXTIME INTEGER)
 * )
 */
function get_timeline($dbh, $user, $count = 10, $start = PHP_INT_MAX) {
    $query = <<<QUERY
SELECT p.post_id, p.tstamp, p.username, p.title, p.bodytext
FROM Post as p
WHERE EXTRACT(EPOCH FROM p.tstamp) < $1
ORDER BY p.tstamp DESC, p.username;
QUERY;
    $result = pg_query_params($dbh, $query, array($start));
    if (!$result) {
        return array( 'status' => 0, 'posts' => null );
    }
    $posts = array();
    $i = 0;
    while ($row = pg_fetch_array($result, $i, MYSQL_ASSOC)) {
        $posts[] = array( 'pID' => $row['post_id'],
                          'username' => $row['username'],
                          'title' => $row['title'],
                          'content' => $row['bodytext'],
                          'time' => strtotime($row['tstamp']) );
        $i++;
    }
    return array( 'status' => 1, 'posts' => $posts );
}

/*
 * Get list of $count most recent posts that were written by user $user
 * before timestamp $start
 * Order by time of the post (going backward in time)
 * Return associative array of the form:
 * array(
 *		'status' =>   (1 for success and 0 for failure)
 *		'posts' => [ (Array of post objects) ]
 * )
 * Each post should be of the form:
 * array(
 *		'pID' => (INTEGER)
 *		'username' => (USERNAME)
 *		'title' => (TITLE)
 *		'content' => (CONTENT)
 *		'time' => (UNIXTIME INTEGER)
 * )
 */
function get_user_posts($dbh, $user, $count = 10, $start = PHP_INT_MAX) {
    $query = <<<QUERY
SELECT p.post_id, p.tstamp, p.username, p.title, p.bodytext
FROM Post as p
WHERE p.username = $1 AND EXTRACT(EPOCH FROM p.tstamp) < $2
ORDER BY p.tstamp DESC, p.username
LIMIT $3;
QUERY;
    $result = pg_query_params($dbh, $query, array($user, $start, $count));
    if (!$result) {
        return array( 'status' => 0, 'posts' => null );
    }
    $posts = array();
    $i = 0;
    while ($row = pg_fetch_array($result, $i, MYSQL_ASSOC)) {
        $posts[] = array( 'pID' => $row['post_id'],
                          'username' => $row['username'],
                          'title' => $row['title'],
                          'content' => $row['bodytext'],
                          'time' => strtotime($row['tstamp']) );
        $i++;
    }
    return array( 'status' => 1, 'posts' => $posts );
}

/*
 * Deletes a post given $user name and $pID.
 * $user must be the one who posted the post $pID.
 * Return associative array of the form:
 * array(
 *		'status' =>   (1 for success. 0 or 2 for failure)
 * )
 */
function delete_post($dbh, $user, $pID) {
    // Validates the deletion request
    $query = <<<QUERY
SELECT * FROM Post
WHERE username = $1 AND post_id = $2;
QUERY;
    $result = pg_query_params($dbh, $query, array($user, $pID));
    if (!$result) {
        return array( 'status' => 0 );
    }
    else if (pg_num_rows($result) == 0) {
        return array( 'status' => 2 );
    }
    // If request is valid (post exists, right ownership), delete
    $query = <<<QUERY
DELETE FROM Post
WHERE username = $1 AND post_id = $2;
QUERY;
    $result = pg_query_params($dbh, $query, array($user, $pID));
    return array( 'status' => (!$result) ? 0 : 1 );
}

/*
 * Records a "like" for a post given logged-in user $me and $pID.
 * Return associative array of the form:
 * array(
 *		'status' =>   (1 for success. 0 for failure)
 * )
 */
function like_post($dbh, $me, $pID) {
    // First check if the post belongs to $me
    $query = <<<QUERY
SELECT * FROM Post
WHERE username = $1 AND post_id = $2;
QUERY;
    $result = pg_query_params($dbh, $query, array($me, $pID));
    if ((!$result) or pg_num_rows($result) > 0) {
        return array( 'status' => 0 );
    }
    // If the user is not the post author, try to "like" it.
    $query = <<<QUERY
INSERT INTO Likes
VALUES ($1, $2);
QUERY;
    $result = pg_query_params($dbh, $query, array($me, $pID));
    return array( 'status' => (!$result) ? 0 : 1 );
}

/*
 * Check if $me has already liked post $pID
 * Return true if user $me has liked post $pID or false otherwise
 */
function already_liked($dbh, $me, $pID) {
    $query = <<<QUERY
SELECT * FROM Likes
WHERE username = $1 AND post_id = $2;
QUERY;
    $result = pg_query_params($dbh, $query, array($me, $pID));
    if (!$result) {
        return false;
    } else {
        return pg_num_rows($result) != 0;
    }
}

/*
 * Find the $count most recent posts that contain the string $key
 * Order by time of the post and break ties by the username (sorted alphabetically A-Z)
 * Return associative array of the form:
 * array(
 *		'status' =>   (1 for success and 0 for failure)
 *		'posts' => [ (Array of Post objects) ]
 * )
 */
function search($dbh, $key, $count = 50) {
    $escaped_key = pg_escape_string($dbh, $key);
    $query = <<<QUERY
SELECT p.post_id, p.tstamp, p.username, p.title, p.bodytext
FROM Post AS p
WHERE p.bodytext LIKE '%{$escaped_key}%'
ORDER BY p.tstamp DESC, p.username
LIMIT $1;
QUERY;
    $result = pg_query_params($dbh, $query, array($count));
    if (!$result) {
        return array( 'status' => 0, 'posts' => null );
    }
    $posts = array();
    $i = 0;
    while ($row = pg_fetch_array($result, $i, MYSQL_ASSOC)) {
        $posts[] = array( 'pID' => $row['post_id'],
                          'username' => $row['username'],
                          'title' => $row['title'],
                          'content' => $row['bodytext'],
                          'time' => strtotime($row['tstamp']) );
        $i++;
    }
    return array( 'status' => 1, 'posts' => $posts );
}

/*
 * Find all users whose username includes the string $name
 * Sort the users alphabetically (A-Z)
 * Return associative array of the form:
 * array(
 *		'status' =>   (1 for success and 0 for failure)
 *		'users' => [ (Array of user IDs) ]
 * )
 */
function user_search($dbh, $name) {
    $escaped_name = pg_escape_string($dbh, $name);
    $query = <<<QUERY
SELECT username FROM Users
WHERE username LIKE '%{$escaped_name}%';
QUERY;
    $result = pg_query($dbh, $query);
    if (!$result) {
        return array( 'status' => 0, 'users' => null );
    }
    $users = array();
    $i = 0;
    while ($row = pg_fetch_array($result, $i, MYSQL_ASSOC)) {
        $users[] = $row['username'];
        $i++;
    }
    return array( 'status' => 1, 'users' => $users );
}


/*
 * Get the number of likes of post $pID
 * Return associative array of the form:
 * array(
 *		'status' =>   (1 for success and 0 for failure)
 *		'count' => (The number of likes)
 * )
 */
function get_num_likes($dbh, $pID) {
    $query = <<<QUERY
SELECT COUNT(*) FROM Likes
WHERE post_id = $1;
QUERY;
    $result = pg_query_params($dbh, $query, array($pID));
    if (!$result or pg_num_rows($result) == 0) {
        return array( 'status' => 0, 'count' => null );
    }
    else {
        $row = pg_fetch_array($result);
        return array( 'status' => 1,
                      'count' => $row['count']
                    );
    }
}

/*
 * Get the number of posts of user $uID
 * Return associative array of the form:
 * array(
 *		'status' =>   (1 for success and 0 for failure)
 *		'count' => (The number of posts)
 * )
 */
function get_num_posts($dbh, $uID) {
    $query = <<<QUERY
SELECT COUNT(*) FROM Post
WHERE username = $1;
QUERY;
    $result = pg_query_params($dbh, $query, array($uID));
    if (!$result or pg_num_rows($result) == 0) {
        return array( 'status' => 0, 'count' => null );
    }
    else {
        $row = pg_fetch_array($result);
        return array( 'status' => 1,
                      'count' => $row['count']
                    );
    }
}

/*
 * Get the number of likes user $uID made
 * Return associative array of the form:
 * array(
 *		'status' =>   (1 for success and 0 for failure)
 *		'count' => (The number of likes)
 * )
 */
function get_num_likes_of_user($dbh, $uID) {
    $query = <<<QUERY
SELECT COUNT(*) FROM Likes
WHERE username = $1;
QUERY;
    $result = pg_query_params($dbh, $query, array($uID));
    if (!$result or pg_num_rows($result) == 0) {
        return array( 'status' => 0, 'count' => null );
    }
    else {
        $row = pg_fetch_array($result);
        return array( 'status' => 1,
                      'count' => $row['count']
                    );
    }
}

/*
 * Get the list of $count users that have posted the most
 * Order by the number of posts (descending), and then by username (A-Z)
 * Return associative array of the form:
 * array(
 *		'status' =>   (1 for success and 0 for failure)
 *		'users' => [ (Array of user IDs) ]
 * )
 */
function get_most_active_users($dbh, $count = 10) {
    $query = <<<QUERY
SELECT username FROM Post
GROUP BY username
ORDER BY COUNT(*) DESC, username
LIMIT $1;
QUERY;
    $result = pg_query_params($dbh, $query, array($count));
    if (!$result) {
        return array( 'status' => 0, 'users' => null );
    }
    $users = array();
    $i = 0;
    while ($row = pg_fetch_array($result, $i, MYSQL_ASSOC)) {
        $users[] = $row['username'];
        $i++;
    }
    return array( 'status' => 1, 'users' => $users );
}

/*
 * Get the list of $count posts posted after $from that have the most likes.
 * Order by the number of likes (descending)
 * Return associative array of the form:
 * array(
 *		'status' =>   (1 for success and 0 for failure)
 *		'posts' => [ (Array of post objects) ]
 * )
 * Each post should be of the form:
 * array(
 *              'pID' => (INTEGER)
 *              'username' => (USERNAME)
 *              'title' => (TITLE OF POST)
 *              'content' => (CONTENT OF POST)
 *              'time' => (UNIXTIME INTEGER)
 * )
 */
function get_most_popular_posts($dbh, $count = 10, $from = 0) {
    $query = <<<QUERY
SELECT p.post_id, p.tstamp, p.username, p.title, p.bodytext
FROM Post AS p
LEFT JOIN (SELECT post_id, COUNT(*) AS likes FROM Likes GROUP BY post_id)
    AS LikeCount ON LikeCount.post_id = p.post_id
WHERE EXTRACT(EPOCH FROM p.tstamp) > $1
ORDER BY likecount.likes DESC NULLS LAST
LIMIT $2;
QUERY;
    $result = pg_query_params($dbh, $query, array($from, $count));
    if (!$result) {
        return array( 'status' => 0, 'posts' => null );
    }
    $posts = array();
    $i = 0;
    while ($row = pg_fetch_array($result, $i, MYSQL_ASSOC)) {
        $posts[] = array( 'pID' => $row['post_id'],
                          'username' => $row['username'],
                          'title' => $row['title'],
                          'content' => $row['bodytext'],
                          'time' => strtotime($row['tstamp']) );
        $i++;
    }
    return array( 'status' => 1, 'posts' => $posts );
}

/*
 * Recommend posts for user $user.
 * A post $p is a recommended post for $user if like minded users of $user also like the post,
 * where like minded users are users who like the posts $user likes.
 * Result should not include posts $user liked.
 * Rank the recommended posts by how many like minded users like the posts.
 * The set of like minded users should not include $user self.
 *
 * Return associative array of the form:
 * array(
 *    'status' =>   (1 for success and 0 for failure)
 *    'posts' => [ (Array of post objects) ]
 * )
 * Each post should be of the form:
 * array(
 *              'pID' => (INTEGER)
 *              'username' => (USERNAME)
 *              'title' => (TITLE OF POST)
 *              'content' => (CONTENT OF POST)
 *              'time' => (UNIXTIME INTEGER)
 * )
 */
function get_recommended_posts($dbh, $count = 10, $user) {
    $escaped_user = pg_escape_string($dbh, $user);
    $query_setup = <<<QUERY
DROP VIEW IF EXISTS recommended_posts CASCADE;
DROP VIEW IF EXISTS like_minded CASCADE;
DROP VIEW IF EXISTS liked_post;
CREATE VIEW liked_post(pid) AS
    SELECT post_id FROM Likes
    WHERE username = '{$escaped_user}';
CREATE VIEW like_minded(username) AS
    SELECT l.username FROM Likes AS l, liked_post AS p
    WHERE l.post_id = p.pid AND l.username <> '{$escaped_user}'
    GROUP BY l.username;
CREATE VIEW recommended_posts(pid, overlap) AS
    SELECT l.post_id, COUNT(*)
    FROM Likes AS l, like_minded AS u
    WHERE l.username = u.username
      AND l.post_id <> ALL(SELECT pid FROM liked_post)
      GROUP BY l.post_id;
QUERY;
    $result = pg_query($dbh, $query_setup);
    if (!$result) {
        return array( 'status' => 0, 'posts' => null );
    }
    $query = <<<QUERY
SELECT p.post_id, p.tstamp, p.username, p.title, p.bodytext
FROM Post AS p, recommended_posts AS r
WHERE r.pid = p.post_id
ORDER BY r.overlap DESC
LIMIT $1;
QUERY;
    $result = pg_query_params($dbh, $query, array($count));
    if (!$result) {
        return array( 'status' => 0, 'posts' => null );
    }
    $posts = array();
    $i = 0;
    while ($row = pg_fetch_array($result, $i, MYSQL_ASSOC)) {
        $posts[] = array( 'pID' => $row['post_id'],
                          'username' => $row['username'],
                          'title' => $row['title'],
                          'content' => $row['bodytext'],
                          'time' => strtotime($row['tstamp']) );
        $i++;
    }
    $query_cleanup = <<<QUERY
DROP VIEW IF EXISTS recommended_posts CASCADE;
DROP VIEW IF EXISTS like_minded CASCADE;
DROP VIEW IF EXISTS liked_post;
QUERY;
    $result = pg_query($dbh, $query_cleanup);
    if (!$result) {
        return array( 'status' => 0, 'posts' => null );
    }
    return array( 'status' => 1, 'posts' => $posts );
}

/*
 * Delete all tables in the database and then recreate them (without any data)
 * Return associative array of the form:
 * array(
 *		'status' =>   (1 for success and 0 for failure)
 * )
 */
function reset_database($dbh) {
    $query_cleanup = 'DROP TABLE IF EXISTS Likes, Post, Users;';
    $query_users  = <<<QUERY
CREATE TABLE Users (
    username VARCHAR(32) NOT NULL,
    password VARCHAR(32) NOT NULL,
    PRIMARY KEY (username)
);
QUERY;
    $query_post = <<<QUERY
CREATE TABLE Post (
    post_id INT NOT NULL,
    tstamp TIMESTAMP NOT NULL,
    username VARCHAR(32) NOT NULL,
    title VARCHAR(50) NOT NULL,
    bodytext VARCHAR(150) NOT NULL,
    PRIMARY KEY(post_id)
);
QUERY;
    $query_likes = <<<QUERY
CREATE TABLE Likes (
    username VARCHAR(32) NOT NULL,
    post_id INT NOT NULL,
    PRIMARY KEY(username, post_id),
    FOREIGN KEY (username) REFERENCES Users ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES Post ON DELETE CASCADE
);
QUERY;
    $result = pg_query($dbh, $query_cleanup);
    $result &= pg_query($dbh, $query_users);
    $result &= pg_query($dbh, $query_post);
    $result &= pg_query($dbh, $query_likes);
    if (!result) {
        return array( 'status' => 0 );
    }
    else {
        return array( 'status' => 1 );
    }
}

?>
