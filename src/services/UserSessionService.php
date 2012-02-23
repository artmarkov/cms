<?php
namespace Blocks;

/**
 *
 */
class UserSessionService extends \CWebUser
{
	const FLASH_KEY_PREFIX = 'Blocks.UserSessionService.flash.';
	const FLASH_COUNTERS   = 'Blocks.UserSessionService.flashcounters';

	/**
	 * Returns the User model of the currently logged in user and null if is user is not logged in.
	 * @return User The model of the logged in user.
	 */
	public function getUser()
	{
		return Blocks::app()->users->getById($this->id);
	}

	/**
	 * @param null $defaultUrl
	 * @return mixed
	 */
	public function getReturnUrl($defaultUrl = null)
	{
		return $this->getState('__returnUrl', $defaultUrl === null ? 'dashboard' : HtmlHelper::normalizeUrl($defaultUrl));
	}

	/**
	 * @throws HttpException
	 */
	public function loginRequired()
	{
		$app = Blocks::app();
		$request = $app->getRequest();

		if (!$request->getIsAjaxRequest())
		{
			if ($request->pathInfo !== '')
				$this->setReturnUrl($request->url);
		}
		elseif (isset($this->loginRequiredAjaxResponse))
		{
			echo $this->loginRequiredAjaxResponse;
			Blocks::app()->end();
		}

		if (($url = $this->loginUrl) !== null)
		{
			if (is_array($url))
			{
				$route = isset($url[0]) ? $url[0] : $app->defaultController;
				$url = $app->createUrl($route, array_splice($url, 1));
			}

			$request->redirect($url);
		}
		else
			throw new HttpException(403, Blocks::t('blocks','Login Required'));
	}


	/**
	 * @param $value
	 */
	public function setReturnUrl($value)
	{
		// strip off any rogue script file names that are prepending the return url.
		$scriptFilePath = Blocks::app()->request->scriptFile;
		$scriptFileName = pathinfo($scriptFilePath, PATHINFO_FILENAME).'.'.pathinfo($scriptFilePath, PATHINFO_EXTENSION);

		if (substr(trim($value, '/'), 0, strlen($scriptFileName)) == $scriptFileName)
			$value = substr(trim($value, '/'), strlen($scriptFileName));

		$this->setState('__returnUrl', $value);
	}

	/**
	 * @param $id
	 * @param $states
	 * @param $fromCookie
	 * @return bool
	 */
	protected function beforeLogin($id, $states, $fromCookie)
	{
		if (isset($states['authSessionToken']))
		{
			$authSessionToken = $states['authSessionToken'];

			$user = Blocks::app()->users->getById($id);

			if ($user === null || $user->auth_session_token !== $authSessionToken)
			{
				// everything is not cool.
				Blocks::log('During login, could not find a user with an id of '.$id.' or the user\'s authSessionToken: '.$authSessionToken.' did not match the one we have on record: '.$user->authToken.'.');
				return false;
			}

			// everything is cool.
			return true;
		}

		// also not cool.
		return false;
	}

	/**
	 * @param $fromCookie
	 */
	protected function afterLogin($fromCookie)
	{
		if ($this->isLoggedIn)
		{
			$this->user->last_login_date = DateTimeHelper::currentTime();
			$this->user->save();
		}
	}

	/**
	 * Saves necessary user data into a cookie.
	 * This method is used when automatic login ({@link allowAutoLogin}) is enabled.
	 * This method saves user ID, username, other identity states and a validation key to cookie.
	 * These information are used to do authentication next time when user visits the application.
	 *
	 * @param integer $duration number of seconds that the user can remain in logged-in status. Defaults to 0, meaning login till the user closes the browser.
	 * @see restoreFromCookie
	*/
	protected function saveToCookie($duration)
	{
		$app = Blocks::app();
		$cookie = $this->createIdentityCookie($this->getStateKeyPrefix());
		$cookie->expire = time() + $duration;
		$cookie->httpOnly = true;

		$data = array(
			$this->getId(),
			$this->getName(),
			$duration,
			$this->saveIdentityStates(),
		);

		$cookie->value = $app->getSecurityManager()->hashData(serialize($data));
		$app->getRequest()->getCookies()->add($cookie->name, $cookie);
	}

	/**
	 * Sets a message for the current user.
	 * @param      $status
	 * @param      $value
	 * @param bool $persistent
	 * @param      $key
	 * @param null $defaultValue
	 */
	public function setMessage($status, $value, $persistent = false, $key = null, $defaultValue = null)
	{
		if ($key == null)
			$key = $status;

		$message = new Message($status, $key, $value, $persistent);
		$this->setState(self::FLASH_KEY_PREFIX.$key, $message, $defaultValue);
		$counters = $this->getState(self::FLASH_COUNTERS, array());

		if ($value === $defaultValue)
			unset($counters[$key]);
		else
			$counters[$key] = 0;

		$this->setState(self::FLASH_COUNTERS, $counters, array());
	}

	/**
	 * Gets all messages for the user and will remove the non-persistent ones.
	 * @return array
	 */
	public function getMessages()
	{
		$flashes = array();
		$prefix = $this->getStateKeyPrefix().self::FLASH_KEY_PREFIX;
		$keys = array_keys($_SESSION);
		$n = strlen($prefix);

		foreach ($keys as $key)
		{
			if (!strncmp($key, $prefix, $n))
			{
				$flashes[substr($key, $n)] = $_SESSION[$key];
				if (!$flashes[substr($key, $n)]->isPersistent())
				{
					unset($_SESSION[$key]);
					$this->setFlash($key, null);
				}
			}
		}

		return $flashes;
	}

	/**
	 * Gets a message with the given key.  If it is not persistent will also remove the message.
	 * @param $key
	 * @param null $defaultValue
	 * @return mixed
	 */
	public function getMessage($key, $defaultValue = null)
	{
		$message = $this->getState(self::FLASH_KEY_PREFIX.$key, $defaultValue);

		if (!$message->isPersistent())
			$this->setFlash($key, null);

		return $message;
	}

	/**
	 * Removes a message with the given key.
	 * @param $key
	 */
	public function removeMessage($key)
	{
		$this->setFlash($key, null);
	}

	/**
	 * Removes all messages (persistent and non-persistent) for the user.
	 */
	public function removeMessages()
	{
		$prefix = $this->getStateKeyPrefix().self::FLASH_KEY_PREFIX;
		$keys = array_keys($_SESSION);
		$n = strlen($prefix);

		foreach ($keys as $key)
		{
			if (!strncmp($key, $prefix, $n))
			{
				unset($_SESSION[$key]);
				$this->setFlash($key, null);
			}
		}
	}

	/**
	 * Gets whether the current user has a message with the given key.
	 * @param $key
	 * @return mixed
	 */
	public function hasMessage($key)
	{
		return $this->hasFlash($key);
	}

	/**
	 * Returns all the messages keys for the current user.
	 * @return array
	 */
	public function getMessageKeys()
	{
		$counters = $this->getState(self::FLASH_COUNTERS);

		if (!is_array($counters))
			return array();

		return array_keys($counters);
	}

	/**
	 * Check to see if the current web user is logged in.
	 * @return bool
	 */
	public function getIsLoggedIn()
	{
		return !$this->getIsGuest();
	}

	/**
	 * @param $loginName
	 * @param $password
	 * @param bool $rememberMe
	 * @return LoginForm|bool
	 */
	public function startLogin($loginName, $password, $rememberMe = false)
	{
		$loginInfo = new LoginForm();
		$loginInfo->loginName = $loginName;
		$loginInfo->password = $password;
		$loginInfo->rememberMe = $rememberMe;

		// validate user input and redirect to the previous page if valid
		if ($loginInfo->validate() && $loginInfo->login())
			return true;

		return $loginInfo;
	}
}
