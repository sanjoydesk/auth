<?php namespace Orchestra\Auth\Acl;

use InvalidArgumentException;
use RuntimeException;
use Orchestra\Auth\Guard;
use Orchestra\Support\Str;
use Orchestra\Memory\Drivers\Driver as MemoryDriver;

class Container {

	/**
	 * Auth instance.
	 *
	 * @var \Illuminate\Auth\Guard
	 */
	protected $auth = null;
	
	/**
	 * Acl instance name.
	 * 
	 * @var string
	 */
	protected $name = null;

	/**
	 * Memory instance.
	 * 
	 * @var \Orchestra\Memory\Drivers\Driver
	 */
	protected $memory = null;

	/**
	 * List of roles.
	 * 
	 * @var \Orchestra\Auth\Acl\Fluent
	 */
	protected $roles = null;
	 
	/**
	 * List of actions.
	 * 
	 * @var \Orchestra\Auth\Acl\Fluent
	 */
	protected $actions = null;
	 
	/**
	 * List of ACL map between roles, action.
	 * 
	 * @var array
	 */
	protected $acl = array();

	/**
	 * Construct a new object.
	 *
	 * @param  \Orchestra\Auth\Guard            $auth
	 * @param  string                           $name
	 * @param  \Orchestra\Memory\Drivers\Driver $memory
	 * @return void
	 */
	public function __construct(Guard $auth, $name, MemoryDriver $memory = null) 
	{
		$this->auth    = $auth;
		$this->name    = $name;
		$this->roles   = new Fluent('roles');
		$this->actions = new Fluent('actions');

		$this->roles->add('guest');
		$this->attach($memory);
	}

	/**
	 * Check whether a Memory instance is already attached to Acl.
	 *
	 * @return boolean
	 */
	public function attached()
	{
		return ( ! is_null($this->memory));
	}

	/**
	 * Bind current ACL instance with a Memory instance.
	 *
	 * @param  \Orchestra\Memory\Drivers\Driver $memory
	 * @return self
	 * @throws \RuntimeException if \Orchestra\Memory\Drivers\Driver has 
	 *                           been attached.
	 */
	public function attach(MemoryDriver $memory = null)
	{
		if ($this->attached())
		{
			throw new RuntimeException(
				"Unable to assign multiple Orchestra\Memory instance."
			);
		}

		// since we already check instanceof, only check for null.
		if (is_null($memory)) return;

		$this->memory = $memory;

		$data = array('acl' => array(), 'actions' => array(), 'roles' => array());
		$data = array_merge($data, $this->memory->get("acl_".$this->name, array()));

		// Loop through all the roles in memory and add it to this ACL 
		// instance.
		foreach ($data['roles'] as $role)
		{
			$this->roles->add($role);
		}

		// Loop through all the actions in memory and add it to this ACL 
		// instance.
		foreach ($data['actions'] as $action)
		{
			$this->actions->add($action);
		}

		// Loop through all the acl in memory and add it to this ACL 
		// instance.
		foreach ($data['acl'] as $id => $allow)
		{
			list($role, $action) = explode(':', $id);
			$this->assign($role, $action, $allow);
		}

		return $this->sync();
	}

	/**
	 * Sync memory with acl instance, make sure anything that added before 
	 * ->with($memory) got called is appended to memory as well.
	 *
	 * @return self
	 */
	public function sync()
	{
		// Loop through all the acl in memory and add it to this ACL 
		// instance.
		foreach ($this->acl as $id => $allow)
		{
			list($role, $action) = explode(':', $id);
			$this->assign($role, $action, $allow);
		}

		if ( ! is_null($this->memory))
		{
			$name = $this->name;

			$this->memory->put("acl_{$name}.actions", $this->actions->get());
			$this->memory->put("acl_{$name}.roles", $this->roles->get());
			$this->memory->put("acl_{$name}.acl", $this->acl);
		}

		return $this;
	}

	/**
	 * Verify whether current user has sufficient roles to access the 
	 * actions based on available type of access.
	 *
	 * @param  string|array     $action     A string of action name
	 * @return boolean
	 */
	public function can($action) 
	{
		$roles = array();
		
		if ( ! $this->auth->guest()) $roles = $this->auth->roles();
		else
		{
			// only add guest if it's available.
			if ($this->roles->has('guest')) array_push($roles, 'guest');
		}

		return $this->check($roles, $action);
	}

	/**
	 * Verify whether given roles has sufficient roles to access the 
	 * actions based on available type of access.
	 *
	 * @param  string|array     $roles      A string or an array of roles
	 * @param  string|array     $action     A string of action name
	 * @return boolean
	 * @throws \InvalidArgumentException
	 */
	public function check($roles, $action) 
	{
		$actions = $this->actions->get();

		if ( ! in_array(Str::slug($action, '-'), $actions)) 
		{
			throw new InvalidArgumentException(
				"Unable to verify unknown action {$action}."
			);
		}

		$action     = Str::slug($action, '-');
		$actionKey = array_search($action, $actions);

		// array_search() will return false when no key is found based on 
		// given haystack, therefore we should just ignore and return false.
		if ($actionKey === false) return false;

		foreach ((array) $roles as $role) 
		{
			$role    = Str::slug($role, '-');
			$roleKey = array_search($role, $this->roles->get());

			// array_search() will return false when no key is found based 
			// on given haystack, therefore we should just ignore and 
			// continue to the next role.
			if ($roleKey === false) continue;

			if (isset($this->acl[$roleKey.':'.$actionKey]))
			{
				return $this->acl[$roleKey.':'.$actionKey];
			}
		}

		return false;
	}

	/**
	 * Assign single or multiple $roles + $actions to have access.
	 * 
	 * @param  string|array     $roles      A string or an array of roles
	 * @param  string|array     $actions    A string or an array of action name
	 * @param  boolean          $allow
	 * @return self
	 * @throws \InvalidArgumentException
	 */
	public function allow($roles, $actions, $allow = true) 
	{
		$roles   = $this->roles->filter($roles);
		$actions = $this->actions->filter($actions);

		foreach ($roles as $role) 
		{
			$role = Str::slug($role, '-');

			if ( ! $this->roles->has($role)) 
			{
				throw new InvalidArgumentException("Role {$role} does not exist.");
			}

			foreach ($actions as $action) 
			{
				$action = Str::slug($action, '-');

				if ( ! $this->actions->has($action)) 
				{
					throw new InvalidArgumentException("Action {$action} does not exist.");
				}

				$this->assign($role, $action, $allow);
				$this->sync();
			}
		}

		return $this;
	}

	/**
	 * Assign a key combination of $roles + $actions to have access.
	 * 
	 * @param  string|array     $roles      A key or string representation of roles
	 * @param  string|array     $actions    A key or string representation of action name
	 * @param  boolean          $allow
	 * @return void
	 */
	protected function assign($role = null, $action = null, $allow = true)
	{
		if ( ! (is_numeric($role) and $this->roles->exist($role)))
		{
			$role = $this->roles->search($role);
		}

		if ( ! (is_numeric($action) and $this->actions->exist($action)))
		{
			$action = $this->actions->search($action);
		}

		if ( ! is_null($role) and ! is_null($action))
		{
			$key = $role.':'.$action;
			$this->acl[$key] = $allow;
		}
	}

	/**
	 * Shorthand function to deny access for single or multiple 
	 * $roles and $actions.
	 * 
	 * @param  string|array     $roles      A string or an array of roles
	 * @param  string|array     $actions    A string or an array of action name
	 * @return self
	 */
	public function deny($roles, $actions) 
	{
		return $this->allow($roles, $actions, false);
	}

	/**
	 * Forward call to roles or actions.
	 *
	 * @param  string   $type           'roles' or 'actions'
	 * @param  string   $operation
	 * @param  array    $parameters
	 * @return \Orchestra\Auth\Acl\Fluent
	 */
	public function execute($type, $operation, $parameters)
	{
		return call_user_func_array(array($this->{$type}, $operation), $parameters);
	}

	/**
	 * Magic method to mimic roles and actions manipulation.
	 * 
	 * @param  string   $method
	 * @param  array    $parameters
	 * @return mixed
	 */
	public function __call($method, $parameters)
	{
		if ($method === 'acl') return $this->acl;
		
		$passthru  = array('roles', 'actions');

		// Not sure whether these is a good approach, allowing a passthru 
		// would allow more expressive structure but at the same time lack 
		// the call to `$this->sync()`, this might cause issue when a request
		// contain remove and add roles/actions.
		if (in_array($method, $passthru)) return $this->{$method};

		// Preserve legacy CRUD structure for actions and roles.
		$method  = Str::snake($method, '_');
		$matcher = '/^(add|fill|rename|has|get|remove)_(role|action)(s?)$/';

		if (preg_match($matcher, $method, $matches))
		{
			$operation = $matches[1];
			$type      = $matches[2].'s';
			$muliple   = (isset($matches[3]) and $matches[3] === 's' and $operation === 'add');

			( !! $muliple) and $operation = 'fill';
			
			$result = $this->execute($type, $operation, $parameters);

			if ($operation === 'has') return $result;
		}

		return $this->sync();
	}
}
