<?php
/**
 * DeepSID / Account Class
 *
 * Validates and maintains users via registrations, login, logout and more. It
 * uses a designated user table in the database. It started as 'FGMemberSite',
 * was modified for GameDeed.com, and then simplified for DeepSID.
 *
 * The original HTML Form Guide v1.0 was published under the terms of the GNU
 * Lesser General Public License: http://www.gnu.org/copyleft/lesser.html
 *
 * @see http://www.html-form-guide.com/php-form/php-registration-form.html
 * @see http://www.html-form-guide.com/php-form/php-login-form.html
 *
 * When a login is successful, the following session variables are available:
 *
 *		$_SESSION['user_name']			user name
 *		$_SESSION['user_id']			user ID
 *
 *		$_SESSION['user_XXXXXXXXXX']	user name (for login check)
 *
 * The "random" 'user_XXXXXXXXXX' is generated by $this->LoginSession().
 *
 * @copyright 2013-2018 Jens-Christian Huus
 */

require_once("setup.php");

$account = new Account();

class Account {

	/**
	 * @link	http://tinyurl.com/randstr
	 */
	private $rand_key			= 'l9iWfvkvzxBIyKK';

	private $database;
	private $error_message;

	/**
	 * Registers a new user.
	 *
	 * @uses		$_POST['submitted'] - set when registration form is submitted
	 * @uses		$_POST[$this->SpamTrapName()] - hidden input field
	 *
	 * @return		boolean		false = call $this->GetErrorMessage()
	 */
	public function RegisterUser() {
		if (!isset($_POST['submitted'])) return false;

		// This is a hidden input field (humans won't fill this field)
		if (!empty($_POST[$this->SpamTrapName()])) {
			// The error message is deliberately vague
			$this->HandleError('A database error occurred; please try again later');
			return false;
		}

		if (!$this->ConnectDB()) return false;

		if (!$this->IsFieldUnique('username')) {
			$this->HandleError('This user name has already been taken');
			return false;
		}        

		if (!$this->CreateUser()) {
			$this->HandleError('Could not create the user in the database');
			return false;
		}

		return true;
	}

	/**
	 * Login an existing user.
	 *
	 * @uses		$_POST['username']
	 * @uses		$_POST['password']
	 *
	 * @return		boolean		false = call $this->GetErrorMessage()
	 */
    public function Login() {
		$username = trim($_POST['username']);
		$password = trim($_POST['password']);

		if (!isset($_SESSION)) session_start();
		if (!$this->ValidateLogin($username, $password))
			return false;

		$_SESSION[$this->LoginSession()] = $username;

		// Remember
		$cookiehash = md5(sha1($_SESSION['user_name'].$_SERVER['REMOTE_ADDR']));
		setcookie('user', $cookiehash, time()+3600*24*365, '/', ($_SERVER['HTTP_HOST'] == LOCALHOST ? 'localhost_deepsid' : 'deepsid.chordian.net'));

		try {
			// Store the hash in the database
			$update = $this->database->prepare('UPDATE users SET session = :cookiehash WHERE username = :username LIMIT 1');
			$update->execute(array(':cookiehash'=>$cookiehash,':username'=>$username));
			if ($update->rowCount() == 0)
				return false;
		} catch(PDOException $exception) {
			$this->LogError($exception->getMessage());
			return false;
		}

		return true;
	}

	/**
	 * Checks if a user is logged in at all.
	 *
	 * @return		boolean		false if user is not logged in
	 */
	public function CheckLogin() {
		if (!isset($_SESSION)) session_start();

		if (empty($_SESSION[$this->LoginSession()]) && !$this->CheckCookieLogin()) return false;

		return true;
	}

	/**
	 * Returns the user name of the logged in user.
	 *
	 * @return		string		user name, or empty if not logged in
	 */
	public function UserName() {
		return isset($_SESSION['user_name']) ? $_SESSION['user_name'] : '';
	}

	/**
	 * Returns the user ID of the logged in user.
	 *
	 * @return		string		user ID, or empty if not logged in
	 */
	public function UserID() {
		return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '';
	}

	/**
	 * Checks if a user name is already taken.
	 *
	 * @return		boolean		false if the user name is taken
	 */
	public function IsUserNameUnique() {
		if (!$this->ConnectDB()) return false;
		return $this->IsFieldUnique('username');
	}

	/**
	 * Logout.
	 */
	public function LogOut() {
		session_start();

		$session = $this->LoginSession();
		$_SESSION[$session] = null;
		unset($_SESSION[$session]);

		setcookie('user', '', time()-3600, '/', ($_SERVER['HTTP_HOST'] == LOCALHOST ? 'localhost_deepsid' : 'deepsid.chordian.net'));
	}

	/**
	 * Changes the password (settings).
	 *
	 * @uses		$_POST['oldpwd']
	 * @uses		$_POST['newpwd']
	 *
	 * @return		boolean		false = call $this->GetErrorMessage()
	 */
	public function ChangePassword() {
		// NOTE: This error should never occur (the settings will not be shown if not logged in).
		if (!$this->CheckLogin()) {
			$this->HandleError('User is not logged in');
			return false;
		}

		if (empty($_POST['oldpwd'])) {
			$this->HandleError('The old password is empty');
			return false;
		}
		if (empty($_POST['newpwd'])) {
			$this->HandleError('The new password is empty');
			return false;
		}

		// Find the user by user name in the database and fill an array
		$profile = array();
		if (!$this->GetProfile($this->UserName(), $profile)) return false;

		if ($profile->password != md5(trim($_POST['oldpwd']))) {
			$this->HandleError('The old password does not match');
			return false;
		}
		// Store the password in the database
		if (!$this->StorePassword($profile, trim($_POST['newpwd']))) return false;

		return true;
	}

	/**
	 * $return		string		name of the current script
	 */
	public function Self() {
		return htmlentities($_SERVER['PHP_SELF']);
	}    

	/**
	 * @uses		$_POST[$value]
	 */
	public function PostValue($value) {
		if (empty($_POST[$value])) return '';
		return htmlentities($_POST[$value]);
	}

	/**
	 * Careful with this command, any HTML code before it and it won't redirect.
	 */
	public function Redirect($url) {
		header('Location: '.$url);
		exit;
	}

	/**
	 */
	public function SpamTrapName() {
		return 'sp'.md5('KHGdnbvsgst'.$this->rand_key);
	}

	/**
	 * Returns an error message if one is stored by HandleError().
	 */
	public function GetErrorMessage() {
		return $this->error_message;
	}    

	/***** PRIVATE FUNCTIONS *****/

	/**
	 * Stores an error message to be retrieved by GetErrorMessage().
	 *
	 * @param		string		error message
	 */
	private function HandleError($message) {
		$this->error_message = $message;
	}

	/**
	 * Stores database error message for use by a call to GetErrorMessage(). Also logs
	 * the elaborate PDO error to a file for inspection by an administrator.
	 *
	 * @param		string		reason for the error (for the log only)
	 * @param		[string]	public error message (default message is common/vague)
	 */
	private function LogError($reason, $message = 'A database error occurred; please try again later') {
		$this->HandleError($message);
		$path = $_SERVER['DOCUMENT_ROOT'];
		// Time and IP address is first in log entry
		$time_and_ip = date('Y-m-d H:i:s', strtotime(TIME_ADJUST)).' - '.$_SERVER['REMOTE_ADDR'].' - ';
		// We need to know the line number and the name of the function that generated this error
		$callers = debug_backtrace();
		// The detailed error is logged to a file on the web server
		file_put_contents($path.'/deepsid/logs/db_errors_account.txt', $time_and_ip.'Line '.$callers[0]['line'].' in '.$callers[1]['function'].'(): "'.$message.'"'.PHP_EOL.
			'Reason: '.$reason.PHP_EOL.PHP_EOL, FILE_APPEND);
	}

	/**
	 * Used by $_SESSION['user_XXXXXXXXXX'] as a login flag.
	 *
	 * @return		string		user_XXXXXXXXXX
	 */
	private function LoginSession() {
		return 'user_'.substr(md5($this->rand_key), 0, 10);
	}

	/**
	 * Checks that the user name and password is correct. After 5 failed login attempts, temporary banning
	 * is activated for 10 minutes.
	 *
	 * @param		string		user name
	 * @param		string		password
	 *
	 * @return		boolean		false = call $this->GetErrorMessage()
	 */
    private function ValidateLogin($username, $password) {
		if (!$this->ConnectDB()) return false;

		try {
			$select = $this->database->prepare('SELECT id, username, attempts, logintime'.
				' FROM users WHERE username = :username AND password = :pwdmd5 LIMIT 1');
			$select->execute(array(':username'=>$username,':pwdmd5'=>md5($password)));
			$select->setFetchMode(PDO::FETCH_OBJ);
			if ($select->rowCount() == 0) {
				// No results - try again without password to see if the user exists at all
				$select = $this->database->prepare('SELECT username, attempts FROM users WHERE username = :username LIMIT 1');
				$select->execute(array(':username'=>$username));
				$select->setFetchMode(PDO::FETCH_OBJ);
				if ($select->rowCount() == 0) {
					$this->HandleError('Error logging in; the user name does not exist');
					return false;
				} else {
					// So the user is there, the password was just wrong
					$row = $select->fetch();
					$attempts = $row->attempts + 1;

					$update = $this->database->prepare('UPDATE users SET attempts = :attempts WHERE username = :username LIMIT 1');
					$update->execute(array(':attempts'=>$attempts,':username'=>$username));
					if ($update->rowCount() == 0) {
						$this->LogError('No rows found after increasing login attempts for the user "'.$username.'"');
						return false;
					}

					if ($attempts > 5) {
						// Reached 5 attempts or more, so set a time stamp in the database
						// NOTE: Additional attempts after 5 will just update this time and thus reset the 10 minutes continuously.
						$logintime = date('Y-m-d H:i:s', strtotime(TIME_ADJUST));
						// Storing the IP address of the failed attempt is strictly for internal informational purposes. If the
						// same IP is seen for a ton of users, it could be a hacker and you would want to ban the IP address.
						$failip = $_SERVER['REMOTE_ADDR'];
						$update = $this->database->prepare('UPDATE users SET logintime = :logintime, failip = :failip'.
							' WHERE username = :username LIMIT 1');
						$update->execute(array(':logintime'=>$logintime,':failip'=>$failip,':username'=>$username));
						if ($update->rowCount() == 0) {
							$this->LogError('No rows found after updating login time and failed IP address '.$failip.' for the user "'.$username.'"');
							return false;
						}
						$this->HandleError("You have been temporarily banned for 10 minutes");
					} else {
						$this->HandleError('The password is incorrect');
					}
					return false;
				}
			} else {
				// The user and password is correct
				$row = $select->fetch();

				$minutes = round((strtotime(date('Y-m-d H:i:s', strtotime(TIME_ADJUST))) - strtotime($row->logintime)) / 60);
				if ($row->attempts >= 5 && $minutes < 10) {
					$this->HandleError('You are still banned for another '.(10 - $minutes).' minutes');
					return false;
				}

				// Make sure attempts are back to 0
				$update = $this->database->prepare('UPDATE users SET attempts = 0 WHERE username = :username LIMIT 1');
				$update->execute(array(':username'=>$username));
				if ($update->rowCount() == 0) {
					$this->LogError('No rows found after resetting login attempts for the user "'.$username.'"');
					return false;
				}

				$_SESSION['user_name']	= $row->username;
				$_SESSION['user_id']	= $row->id;

				return true;
			}
		} catch(PDOException $exception) {
			$this->LogError($exception->getMessage());
			return false;
		}
    }

	/**
	 * Checks to see if user is remembered via a cookie.
	 *
	 * Only called by CheckLogin().
	 *
	 * @return		boolean		false if not remembering login (or database error)
	 */
	private function CheckCookieLogin() {
		if (isset($_COOKIE['user'])) {
			$cookiehash = $_COOKIE['user'];

			if (!empty($cookiehash)) {
				if (!$this->ConnectDB()) return false;
				try {
					$select = $this->database->prepare('SELECT id, username FROM users WHERE session = :cookiehash LIMIT 1');
					$select->execute(array(':cookiehash'=>$cookiehash));
					$select->setFetchMode(PDO::FETCH_OBJ);
					if ($select->rowCount() > 0) {
						$row = $select->fetch();
						$_SESSION['user_name']	= $row->username;
						$_SESSION['user_id']	= $row->id;
						$_SESSION[$this->LoginSession()] = $row->username;

						// Reset the expiry date
						setcookie('user', $cookiehash, time()+3600*24*365, '/', ($_SERVER['HTTP_HOST'] == LOCALHOST ? 'localhost_deepsid' : 'deepsid.chordian.net'));
						return true;
					} else {
						$this->LogError('No rows found containing the cookie hash "'.$cookiehash.'"');
						return false;
					}
				} catch(PDOException $exception) {
					$this->LogError($exception->getMessage());
					return false;
				}
			}
		}
		return false;
	}

	/**
	 * Stores the password in the database.
	 *
	 * @param		array		user information
	 * @param		string		the new password
	 *
	 * @return		boolean		false = call $this->GetErrorMessage()
	 */
	private function StorePassword($profile, $newpwd) {
		try {
			$update = $this->database->prepare('UPDATE users SET password = :password WHERE id = :user_id LIMIT 1');
			$update->execute(array(':password'=>md5($newpwd),':user_id'=>$profile->id));
			if ($update->rowCount() == 0) {
				$this->LogError('No rows found after updating the password for the user "'.$profile->username.'"');
				return false;
			}
		} catch(PDOException $exception) {
			$this->LogError($exception->getMessage());
			return false;
		}
		return true;
	}

	/**
	 * Finds the user by user name and fills a profile array.
	 *
	 * @param		string		user name
	 * @param		array		user information (referenced array to be filled)
	 *
	 * @return		boolean		false = call $this->GetErrorMessage()
	 */
	private function GetProfile($username, &$profile) {
		if (!$this->ConnectDB()) return false;

		try {
			$select = $this->database->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
			$select->execute(array(':username'=>$username));
			$select->setFetchMode(PDO::FETCH_OBJ);
			if ($select->rowCount() == 0) {
				$this->HandleError('No user is registered with this user name: '.$username);
				return false;
			}
			$profile = $select->fetch(); // Fill user information array
		} catch(PDOException $exception) {
			$this->LogError($exception->getMessage());
			return false;
		}
		return true;
	}

	/**
	 * Returns the absolute URL to the web site including the 'http[s]://' part.
	 *
	 * @return		string		when online it's 'http://deepsid.chordian.net'
	 */
	private function SiteURL() {
		return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? 'https://' : 'http://').$_SERVER['HTTP_HOST'];
	}

	/**
	 * Checks if a field is unique in the database table.
	 *
	 * @param		string		field name
	 *
	 * @return		boolean		false if the field is already there
	 */
	private function IsFieldUnique($field) {
		try {
			$select = $this->database->prepare('SELECT username FROM users WHERE '.$field.'=:postfield');
			$select->execute(array(':postfield'=>$_POST[$field]));
			if ($select->rowCount() > 0) return false;
		} catch(PDOException $exception) {
			$this->LogError($exception->getMessage());
			return false;
		}
		return true;
	}

	/**
	 * Connects to the database using PDO. Call this before any database operation.
	 *
	 * @return		boolean		false = call $this->GetErrorMessage()
	 */
	private function ConnectDB() {
		try {
			$options = array(
				PDO::MYSQL_ATTR_FOUND_ROWS	=> true, // So that UPDATE statements actually return a row
				PDO::ATTR_ERRMODE			=> PDO::ERRMODE_EXCEPTION
			);
			if ($_SERVER['HTTP_HOST'] == LOCALHOST) {
				$this->database = new PDO(PDO_LOCALHOST, USER_LOCALHOST, PWD_LOCALHOST, $options);
			} else {
				$this->database = new PDO(PDO_ONLINE, USER_ONLINE, PWD_ONLINE, $options);
			}
			$this->database->exec("SET NAMES UTF8");
		} catch(PDOException $exception) {
			$this->LogError($exception->getMessage());
			return false;
		}
		return true;
	}

	/**
	 * Adds a new user by inserting the $_POST variables in a new row.
	 *
	 * @return		boolean		false = call $this->GetErrorMessage()
	 */
	private function CreateUser() {
		try {
			$insert = $this->database->prepare('INSERT INTO users(
				username,
				password,
				joined,
				session,
				attempts,
				logintime,
				failip
				) VALUES (
				:username,
				:password,
				CURDATE(),
				"",
				"0",
				:logintime,
				"0.0.0.0"
				)');
			$insert->execute(array(
				':username'		=> $_POST['username'],
				':password'		=> md5($_POST['password']),
				':logintime'	=> date('Y-m-d H:i:s', strtotime(TIME_ADJUST))
				));
			if ($insert->rowCount() == 0) {
				$this->LogError('No rows found after inserting a new table row for the user "'.$_POST['username'].'"');
				return false;
			}
		} catch(PDOException $exception) {
			$this->LogError($exception->getMessage());
			return false;
		}
		return true;
	}
}
?>