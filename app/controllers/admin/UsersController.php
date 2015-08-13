<?php

namespace Controllers\Admin;

use AdminController;
use Cartalyst\Sentry\Users\LoginRequiredException;
use Cartalyst\Sentry\Users\PasswordRequiredException;
use Cartalyst\Sentry\Users\UserExistsException;
use Cartalyst\Sentry\Users\UserNotFoundException;
use HTML;
use URL;
use Config;
use DB;
use Input;
use User;
use Asset;
use Lang;
use Actionlog;
use Location;
use Setting;
use Redirect;
use Response;
use Sentry;
use Str;
use Validator;
use Statuslabel;
use View;
use Datatable;
use League\Csv\Reader;
use Mail;
use Accessory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Illuminate\Support\Facades\Log;

class UsersController extends AdminController {

    /**
     * Declare the rules for the form validation
     *
     * @var array
     */
    protected $validationRules = array(
        'first_name' => 'required|alpha_space|min:2',
        'last_name' => 'required|alpha_space|min:2',
        'location_id' => 'numeric',
        'username' => 'required|min:2|unique:users,username',
        'email' => 'email|unique:users,email',
        'password' => 'required|min:6',
        'password_confirm' => 'required|min:6|same:password',
    );

    /**
     * Show a list of all the users.
     *
     * @return View
     */
    public function getIndex() {

        // Show the page
        return View::make('backend/users/index');
    }

    /**
     * User create.
     *
     * @return View
     */
    public function getCreate() {
        // Get all the available groups
        $groups = Sentry::getGroupProvider()->findAll();

        // Selected groups
        $userGroups = Input::old('groups', array());

        // Get all the available permissions
        $permissions = Config::get('permissions');
        $this->encodeAllPermissions($permissions);

        // Selected permissions
        $userPermissions = Input::old('permissions', array('superuser' => -1));
        $this->encodePermissions($userPermissions);

        $location_list = array('' => '') + Location::lists('name', 'id');
        $manager_list = array('' => '') + DB::table('users')
                        ->select(DB::raw('concat(last_name,", ",first_name," (",username,")") as full_name, id'))
                        ->whereNull('deleted_at', 'and')
                        ->orderBy('last_name', 'asc')
                        ->orderBy('first_name', 'asc')
                        ->lists('full_name', 'id');

        /* echo '<pre>';
          print_r($userPermissions);
          echo '</pre>';
          exit;
         */

        // Show the page
        return View::make('backend/users/edit', compact('groups', 'userGroups', 'permissions', 'userPermissions'))
                        ->with('location_list', $location_list)
                        ->with('manager_list', $manager_list)
                        ->with('user', new User);
    }

    /**
     * User create form processing.
     *
     * @return Redirect
     */
    public function postCreate() {
        // Create a new validator instance from our validation rules
        $validator = Validator::make(Input::all(), $this->validationRules);
        $permissions = Input::get('permissions', array());
        $this->decodePermissions($permissions);
        app('request')->request->set('permissions', $permissions);

        // If validation fails, we'll exit the operation now.
        if ($validator->fails()) {
            // Ooops.. something went wrong
            return Redirect::back()->withInput()->withErrors($validator)->with('permissions', $permissions);
        }

        try {
            // We need to reverse the UI specific logic for our
            // permissions here before we create the user.
            // Get the inputs, with some exceptions
            $inputs = Input::except('csrf_token', 'password_confirm', 'groups', 'email_user');

            // @TODO: Figure out WTF I need to do this.
            if ($inputs['manager_id'] == '') {
                unset($inputs['manager_id']);
            }

            if ($inputs['location_id'] == '') {
                unset($inputs['location_id']);
            }

            // Was the user created?
            if ($user = Sentry::getUserProvider()->create($inputs)) {

                // Assign the selected groups to this user
                foreach (Input::get('groups', array()) as $groupId) {
                    $group = Sentry::getGroupProvider()->findById($groupId);
                    $user->addGroup($group);
                }

                // Prepare the success message
                $success = Lang::get('admin/users/message.success.create');

                // Redirect to the new user page
                //return Redirect::route('update/user', $user->id)->with('success', $success);

                if ((Input::get('email_user') == 1) && (Input::has('email'))) {
                    // Send the credentials through email

                    $data = array();
                    $data['email'] = e(Input::get('email'));
                    $data['username'] = e(Input::get('username'));
                    $data['first_name'] = e(Input::get('first_name'));
                    $data['password'] = e(Input::get('password'));

                    Mail::send('emails.send-login', $data, function ($m) use ($user) {
                        $m->to($user->email, $user->first_name . ' ' . $user->last_name);
                        $m->subject('Welcome ' . $user->first_name);
                    });
                }


                return Redirect::route('users')->with('success', $success);
            }



            // Prepare the error message
            $error = Lang::get('admin/users/message.error.create');

            // Redirect to the user creation page
            return Redirect::route('create/user')->with('error', $error);
        } catch (LoginRequiredException $e) {
            $error = Lang::get('admin/users/message.user_login_required');
        } catch (PasswordRequiredException $e) {
            $error = Lang::get('admin/users/message.user_password_required');
        } catch (UserExistsException $e) {
            $error = Lang::get('admin/users/message.user_exists');
        }

        // Redirect to the user creation page
        return Redirect::route('create/user')->withInput()->with('error', $error);
    }

    public function store() {
        // Create a new validator instance from our validation rules
        $validator = Validator::make(Input::all(), $this->validationRules);
        $permissions = Input::get('permissions', array());
        $this->decodePermissions($permissions);
        app('request')->request->set('permissions', $permissions);

        // If validation fails, we'll exit the operation now.
        if ($validator->fails()) {
            // Ooops.. something went wrong
            return JsonResponse::create(["error" => "Failed validation: " . print_r($validator->messages()->all('<li>:message</li>'), true)], 500);
        }

        try {
            // We need to reverse the UI specific logic for our
            // permissions here before we create the user.
            // Get the inputs, with some exceptions
            $inputs = Input::except('csrf_token', 'password_confirm', 'groups', 'email_user');
            $inputs['activated'] = true;


            // @TODO: Figure out WTF I need to do this.
            /* if ($inputs['manager_id']=='') {
              unset($inputs['manager_id']);
              } */

            /* if ($inputs['location_id']=='') {
              unset($inputs['location_id']);
              } */

            // Was the user created?
            if ($user = Sentry::getUserProvider()->create($inputs)) {

                if (Input::get('email_user') == 1) {
                    // Send the credentials through email

                    $data = array();
                    $data['email'] = e(Input::get('email'));
                    $data['first_name'] = e(Input::get('first_name'));
                    $data['password'] = e(Input::get('password'));

                    Mail::send('emails.send-login', $data, function ($m) use ($user) {
                        $m->to($user->email, $user->first_name . ' ' . $user->last_name);
                        $m->subject('Welcome ' . $user->first_name);
                    });
                }


                return JsonResponse::create($user);
            } else {
                return JsonResponse::create(["error" => "Couldn't save User"], 500);
            }
        } catch (Exception $e) {

            // Redirect to the user creation page
            return JsonResponse::create(["error" => "Failed validation: " . print_r($validator->messages()->all('<li>:message</li>'), true)], 500);
        }
    }

    /**
     * User update.
     *
     * @param  int  $id
     * @return View
     */
    public function getEdit($id = null) {
        try {
            // Get the user information
            $user = Sentry::getUserProvider()->findById($id);

            // Get this user groups
            $userGroups = $user->groups()->lists('group_id', 'name');

            // Get this user permissions
            $userPermissions = array_merge(Input::old('permissions', array('superuser' => -1)), $user->getPermissions());
            $this->encodePermissions($userPermissions);

            // Get a list of all the available groups
            $groups = Sentry::getGroupProvider()->findAll();

            // Get all the available permissions
            $permissions = Config::get('permissions');
            $this->encodeAllPermissions($permissions);

            $location_list = array('' => '') + Location::lists('name', 'id');
            $manager_list = array('' => 'Select a User') + DB::table('users')
                            ->select(DB::raw('concat(last_name,", ",first_name," (",email,")") as full_name, id'))
                            ->whereNull('deleted_at')
                            ->where('id', '!=', $id)
                            ->orderBy('last_name', 'asc')
                            ->orderBy('first_name', 'asc')
                            ->lists('full_name', 'id');
        } catch (UserNotFoundException $e) {
            // Prepare the error message
            $error = Lang::get('admin/users/message.user_not_found', compact('id'));

            // Redirect to the user management page
            return Redirect::route('users')->with('error', $error);
        }

        // Show the page
        return View::make('backend/users/edit', compact('user', 'groups', 'userGroups', 'permissions', 'userPermissions'))
                        ->with('location_list', $location_list)
                        ->with('manager_list', $manager_list);
    }

    /**
     * User update form processing page.
     *
     * @param  int  $id
     * @return Redirect
     */
    public function postEdit($id = null) {
        // We need to reverse the UI specific logic for our
        // permissions here before we update the user.
        $permissions = Input::get('permissions', array());
        $this->decodePermissions($permissions);
        app('request')->request->set('permissions', $permissions);

        // Only update the email address if locking is set to false
        if (Config::get('app.lock_passwords')) {
            return Redirect::route('users')->with('error', 'Denied! You cannot update user information on the demo.');
        }

        try {
            // Get the user information
            $user = Sentry::getUserProvider()->findById($id);
        } catch (UserNotFoundException $e) {
            // Prepare the error message
            $error = Lang::get('admin/users/message.user_not_found', compact('id'));

            // Redirect to the user management page
            return Redirect::route('users')->with('error', $error);
        }

        //Check if username is the same then unset validationRules
        if (Input::get('username') == $user->username) {
            unset($this->validationRules['username']);
        }

        //Check if email is the same then unset validationRules
        if ($user->email == Input::get('email')) {
            unset($this->validationRules['email']);
        }

        // Do we want to update the user password?
        if (!$password = Input::get('password')) {
            unset($this->validationRules['password']);
            unset($this->validationRules['password_confirm']);
            #$this->validationRules['password']         = 'required|between:3,32';
            #$this->validationRules['password_confirm'] = 'required|between:3,32|same:password';
        }

        // Create a new validator instance from our validation rules
        $validator = Validator::make(Input::all(), $this->validationRules);


        // If validation fails, we'll exit the operation now.
        if ($validator->fails()) {
            // Ooops.. something went wrong
            return Redirect::back()->withInput()->withErrors($validator);
        }

        try {
            // Update the user
            $user->first_name = Input::get('first_name');
            $user->last_name = Input::get('last_name');
            $user->username = Input::get('username');
            $user->email = Input::get('email');
            $user->employee_num = Input::get('employee_num');
            $user->activated = Input::get('activated', $user->activated);
            $user->permissions = Input::get('permissions');
            $user->jobtitle = Input::get('jobtitle');
            $user->phone = Input::get('phone');
            $user->location_id = Input::get('location_id');
            $user->manager_id = Input::get('manager_id');
            $user->notes = Input::get('notes');

            if ($user->manager_id == "") {
                $user->manager_id = NULL;
            }

            if ($user->location_id == "") {
                $user->location_id = NULL;
            }


            // Do we want to update the user password?
            if (($password) && (!Config::get('app.lock_passwords'))) {
                $user->password = $password;
            }

            // Do we want to update the user email?
            if (!Config::get('app.lock_passwords')) {
                $user->email = Input::get('email');
            }

            // Get the current user groups
            $userGroups = $user->groups()->lists('group_id', 'group_id');

            // Get the selected groups
            $selectedGroups = Input::get('groups', array());

            // Groups comparison between the groups the user currently
            // have and the groups the user wish to have.
            $groupsToAdd = array_diff($selectedGroups, $userGroups);
            $groupsToRemove = array_diff($userGroups, $selectedGroups);

            if (!Config::get('app.lock_passwords')) {

                // Assign the user to groups
                foreach ($groupsToAdd as $groupId) {
                    $group = Sentry::getGroupProvider()->findById($groupId);
                    $user->addGroup($group);
                }

                // Remove the user from groups
                foreach ($groupsToRemove as $groupId) {
                    $group = Sentry::getGroupProvider()->findById($groupId);

                    $user->removeGroup($group);
                }
            }

            // Was the user updated?
            if ($user->save()) {
                // Prepare the success message
                $success = Lang::get('admin/users/message.success.update');

                // Redirect to the user page
                return Redirect::route('view/user', $id)->with('success', $success);
            }

            // Prepare the error message
            $error = Lang::get('admin/users/message.error.update');
        } catch (LoginRequiredException $e) {
            $error = Lang::get('admin/users/message.user_login_required');
        }

        // Redirect to the user page
        return Redirect::route('update/user', $id)->withInput()->with('error', $error);
    }

    /**
     * Delete the given user.
     *
     * @param  int  $id
     * @return Redirect
     */
    public function getDelete($id = null) {
        try {
            // Get user information
            $user = Sentry::getUserProvider()->findById($id);

            // Check if we are not trying to delete ourselves
            if ($user->id === Sentry::getId()) {
                // Prepare the error message
                $error = Lang::get('admin/users/message.error.delete');

                // Redirect to the user management page
                return Redirect::route('users')->with('error', $error);
            }


            // Do we have permission to delete this user?
            if ((!Sentry::getUser()->isSuperUser()) || (Config::get('app.lock_passwords'))) {
                // Redirect to the user management page
                return Redirect::route('users')->with('error', 'Insufficient permissions!');
            }

            if (count($user->assets) > 0) {

                // Redirect to the user management page
                return Redirect::route('users')->with('error', 'This user still has ' . count($user->assets) . ' assets associated with them.');
            }

            if (count($user->licenses) > 0) {

                // Redirect to the user management page
                return Redirect::route('users')->with('error', 'This user still has ' . count($user->licenses) . ' licenses associated with them.');
            }

            // Delete the user
            $user->delete();

            // Prepare the success message
            $success = Lang::get('admin/users/message.success.delete');

            // Redirect to the user management page
            return Redirect::route('users')->with('success', $success);
        } catch (UserNotFoundException $e) {
            // Prepare the error message
            $error = Lang::get('admin/users/message.user_not_found', compact('id'));

            // Redirect to the user management page
            return Redirect::route('users')->with('error', $error);
        }
    }

    public function postBulkEdit() {

        if ((!Input::has('edit_user')) || (count(Input::has('edit_user')) == 0)) {
            return Redirect::back()->with('error', 'No users selected');
        } else {
            $statuslabel_list = array('' => Lang::get('general.select_statuslabel')) + Statuslabel::orderBy('name', 'asc')->lists('name', 'id');
            $user_raw_array = Input::get('edit_user');
            $users = User::whereIn('id', $user_raw_array)->with('groups')->get();
            return View::make('backend/users/confirm-bulk-delete', compact('users', 'statuslabel_list'));
        }
    }

    public function postBulkSave() {

        if ((!Input::has('edit_user')) || (count(Input::has('edit_user')) == 0)) {
            return Redirect::back()->with('error', 'No users selected');
        } elseif ((!Input::has('status_id')) || (count(Input::has('status_id')) == 0)) {
            return Redirect::route('users')->with('error', 'No status selected');
        } else {

            $user_raw_array = Input::get('edit_user');
            $asset_array = array();

            if (($key = array_search(Sentry::getId(), $user_raw_array)) !== false) {
                unset($user_raw_array[$key]);
            }

            if (!Config::get('app.lock_passwords')) {

                $assets = Asset::whereIn('assigned_to', $user_raw_array)->get();
                $accessories = DB::table('accessories_users')->whereIn('assigned_to', $user_raw_array)->get();
                $users = User::whereIn('id', $user_raw_array)->delete();

                foreach ($assets as $asset) {

                    $asset_array[] = $asset->id;

                    // Update the asset log
                    $logaction = new Actionlog();
                    $logaction->asset_id = $asset->id;
                    $logaction->checkedout_to = $asset->assigned_to;
                    $logaction->asset_type = 'hardware';
                    $logaction->user_id = Sentry::getUser()->id;
                    $logaction->note = 'Bulk checkin';
                    $log = $logaction->logaction('checkin from');

                    $update_assets = Asset::whereIn('id', $asset_array)->update(
                            array(
                                'status_id' => e(Input::get('status_id')),
                                'assigned_to' => '',
                    ));
                }

                foreach ($accessories as $accessory) {
                    $accessory_array[] = $accessory->id;
                    // Update the asset log
                    $logaction = new Actionlog();
                    $logaction->accessory_id = $accessory->id;
                    $logaction->checkedout_to = $accessory->assigned_to;
                    $logaction->asset_type = 'accessory';
                    $logaction->user_id = Sentry::getUser()->id;
                    $logaction->note = 'Bulk checkin';
                    $log = $logaction->logaction('checkin from');

                    $update_assets = DB::table('accessories_users')->whereIn('id', $accessory_array)->update(
                            array(
                                'assigned_to' => '',
                    ));
                }


                return Redirect::route('users')->with('success', 'Your selected users have been deleted and their assets have been updated.');
            } else {
                return Redirect::route('users')->with('error', 'Bulk delete is not enabled in this installation');
            }

            return Redirect::route('users')->with('error', 'An error has occurred');
        }
    }

    /**
     * Restore a deleted user.
     *
     * @param  int  $id
     * @return Redirect
     */
    public function getRestore($id = null) {
        try {
            // Get user information
            $user = Sentry::getUserProvider()->createModel()->withTrashed()->find($id);

            // Restore the user
            $user->restore();

            // Prepare the success message
            $success = Lang::get('admin/users/message.success.restored');

            // Redirect to the user management page
            return Redirect::route('users')->with('success', $success);
        } catch (UserNotFoundException $e) {
            // Prepare the error message
            $error = Lang::get('admin/users/message.user_not_found', compact('id'));

            // Redirect to the user management page
            return Redirect::route('users')->with('error', $error);
        }
    }

    /**
     * Get user info for user view
     *
     * @param  int  $userId
     * @return View
     */
    public function getView($userId = null) {

        $user = User::with('assets', 'assets.model', 'consumables', 'accessories', 'licenses', 'userloc')->withTrashed()->find($userId);

        $userlog = $user->userlog->load('assetlog', 'consumablelog', 'assetlog.model', 'licenselog', 'accessorylog', 'userlog', 'adminlog');

        if (isset($user->id)) {
            return View::make('backend/users/view', compact('user', 'userlog'));
        } else {
            // Prepare the error message
            $error = Lang::get('admin/users/message.user_not_found', compact('id'));

            // Redirect to the user management page
            return Redirect::route('users')->with('error', $error);
        }
    }

    /**
     * Unsuspend the given user.
     *
     * @param  int      $id
     * @return Redirect
     */
    public function getUnsuspend($id = null) {
        try {
            // Get user information
            $user = Sentry::getUserProvider()->findById($id);

            // Check if we are not trying to unsuspend ourselves
            if ($user->id === Sentry::getId()) {
                // Prepare the error message
                $error = Lang::get('admin/users/message.error.unsuspend');

                // Redirect to the user management page
                return Redirect::route('users')->with('error', $error);
            }

            // Do we have permission to unsuspend this user?
            if ($user->isSuperUser() and ! Sentry::getUser()->isSuperUser()) {
                // Redirect to the user management page
                return Redirect::route('users')->with('error', 'Insufficient permissions!');
            }

            // Unsuspend the user
            $throttle = Sentry::findThrottlerByUserId($id);
            $throttle->unsuspend();

            // Prepare the success message
            $success = Lang::get('admin/users/message.success.unsuspend');

            // Redirect to the user management page
            return Redirect::route('users')->with('success', $success);
        } catch (UserNotFoundException $e) {
            // Prepare the error message
            $error = Lang::get('admin/users/message.user_not_found', compact('id'));

            // Redirect to the user management page
            return Redirect::route('users')->with('error', $error);
        }
    }

    public function getClone($id = null) {
        // We need to reverse the UI specific logic for our
        // permissions here before we update the user.
        $permissions = Input::get('permissions', array());
        $this->decodePermissions($permissions);
        app('request')->request->set('permissions', $permissions);


        try {
            // Get the user information
            $user_to_clone = Sentry::getUserProvider()->findById($id);
            $user = clone $user_to_clone;
            $user->first_name = '';
            $user->last_name = '';
            $user->email = substr($user->email, ($pos = strpos($user->email, '@')) !== false ? $pos : 0);
            ;
            $user->id = null;

            // Get this user groups
            $userGroups = $user_to_clone->groups()->lists('group_id', 'name');

            // Get this user permissions
            $userPermissions = array_merge(Input::old('permissions', array('superuser' => -1)), $user_to_clone->getPermissions());
            $this->encodePermissions($userPermissions);

            // Get a list of all the available groups
            $groups = Sentry::getGroupProvider()->findAll();

            // Get all the available permissions
            $permissions = Config::get('permissions');
            $this->encodeAllPermissions($permissions);

            $location_list = array('' => '') + Location::lists('name', 'id');
            $manager_list = array('' => 'Select a User') + DB::table('users')
                            ->select(DB::raw('concat(last_name,", ",first_name," (",email,")") as full_name, id'))
                            ->whereNull('deleted_at')
                            ->where('id', '!=', $id)
                            ->orderBy('last_name', 'asc')
                            ->orderBy('first_name', 'asc')
                            ->lists('full_name', 'id');

            // Show the page
            return View::make('backend/users/edit', compact('groups', 'userGroups', 'permissions', 'userPermissions'))
                            ->with('location_list', $location_list)
                            ->with('manager_list', $manager_list)
                            ->with('user', $user)
                            ->with('clone_user', $user_to_clone);
        } catch (UserNotFoundException $e) {
            // Prepare the error message
            $error = Lang::get('admin/users/message.user_not_found', compact('id'));

            // Redirect to the user management page
            return Redirect::route('users')->with('error', $error);
        }
    }

    /**
     * User import.
     *
     * @return View
     */
    public function getImport() {
        // Get all the available groups
        $groups = Sentry::getGroupProvider()->findAll();
        // Selected groups
        $selectedGroups = Input::old('groups', array());
        // Get all the available permissions
        $permissions = Config::get('permissions');
        $this->encodeAllPermissions($permissions);
        // Selected permissions
        $selectedPermissions = Input::old('permissions', array('superuser' => -1));
        $this->encodePermissions($selectedPermissions);
        // Show the page
        return View::make('backend/users/import', compact('groups', 'selectedGroups', 'permissions', 'selectedPermissions'));
    }

    /**
     * User import form processing.
     *
     * @return Redirect
     */
    public function postImport() {

        if (!ini_get("auto_detect_line_endings")) {
            ini_set("auto_detect_line_endings", '1');
        }

        $csv = Reader::createFromPath(Input::file('user_import_csv'));
        $csv->setNewline("\r\n");

        if (Input::get('has_headers') == 1) {
            $csv->setOffset(1);
        }

        $duplicates = '';

        $nbInsert = $csv->each(function ($row) use ($duplicates) {

            if (array_key_exists(2, $row)) {

                if (Input::get('activate') == 1) {
                    $activated = '1';
                } else {
                    $activated = '0';
                }

                $pass = substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 10);



                try {
                    // Check if this email already exists in the system
                    $user = DB::table('users')->where('username', $row[2])->first();
                    if ($user) {
                        $duplicates .= $row[2] . ', ';
                    } else {

                        $newuser = array(
                            'first_name' => $row[0],
                            'last_name' => $row[1],
                            'username' => $row[2],
                            'email' => $row[3],
                            'password' => $pass,
                            'activated' => $activated,
                            'location_id' => $row[4],
                            'permissions' => '{"user":1}',
                            'notes' => 'Imported user'
                        );

                        DB::table('users')->insert($newuser);

                        $updateuser = Sentry::findUserByLogin($row[2]);

                        // Update the user details
                        $updateuser->password = $pass;

                        // Update the user
                        $updateuser->save();


                        if (((Input::get('email_user') == 1) && !Config::get('app.lock_passwords'))) {
                            // Send the credentials through email
                            if ($row[3] != '') {
                                $data = array();
                                $data['username'] = $row[2];
                                $data['first_name'] = $row[0];
                                $data['password'] = $pass;

                                if ($newuser['email']) {
                                    Mail::send('emails.send-login', $data, function ($m) use ($newuser) {
                                        $m->to($newuser['email'], $newuser['first_name'] . ' ' . $newuser['last_name']);
                                        $m->subject('Welcome ' . $newuser['first_name']);
                                    });
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    echo 'Caught exception: ', $e->getMessage(), "\n";
                }
                return true;
            }
        });


        return Redirect::route('users')->with('duplicates', $duplicates)->with('success', 'Success');
    }

    public function getDatatable($status = null) {

        $users = User::with('assets', 'accessories', 'consumables', 'licenses', 'manager', 'sentryThrottle', 'groups', 'userloc');

        switch ($status) {
            case 'deleted':
                $users->GetDeleted();
                break;
            case '':
                $users->GetNotDeleted();
                break;
        }

        $users = $users->orderBy('created_at', 'DESC')->get();

        $actions = new \Chumper\Datatable\Columns\FunctionColumn('actions', function ($users) {
            $action_buttons = '';


            if (!is_null($users->deleted_at)) {
                $action_buttons .= '<a href="' . route('restore/user', $users->id) . '" class="btn btn-warning btn-sm"><i class="fa fa-share icon-white"></i></a> ';
            } else {
                if ($users->accountStatus() == 'suspended') {
                    $action_buttons .= '<a href="' . route('unsuspend/user', $users->id) . '" class="btn btn-default btn-sm"><span class="fa fa-clock-o"></span></a> ';
                }

                $action_buttons .= '<a href="' . route('update/user', $users->id) . '" class="btn btn-warning btn-sm"><i class="fa fa-pencil icon-white"></i></a> ';

                if ((Sentry::getId() !== $users->id) && (!Config::get('app.lock_passwords'))) {
                    $action_buttons .= '<a data-html="false" class="btn delete-asset btn-danger btn-sm" data-toggle="modal" href="' . route('delete/user', $users->id) . '" data-content="Are you sure you wish to delete this user?" data-title="Delete ' . htmlspecialchars($users->first_name) . '?" onClick="return false;"><i class="fa fa-trash icon-white"></i></a> ';
                } else {
                    $action_buttons .= ' <span class="btn delete-asset btn-danger btn-sm disabled"><i class="fa fa-trash icon-white"></i></span>';
                }
            }
            return $action_buttons;
        });


        return Datatable::collection($users)
                        ->addColumn('', function($users) {
                            return '<div class="text-center"><input type="checkbox" name="edit_user[]" value="' . $users->id . '" class="one_required"></div>';
                        })
                        ->addColumn('name', function($users) {
                            return '<a title="' . $users->fullName() . '" href="users/' . $users->id . '/view">' . $users->fullName() . '</a>';
                        })
                        ->addColumn('email', function($users) {
                            if ($users->email) {
                                return '<div class="text-center"><a title="' . $users->email . '" href="mailto:' . $users->email . '"><i class="fa fa-envelope fa-lg"></i></div>';
                            } else {
                                return '';
                            }
                        })
                        ->addColumn('manager', function($users) {
                            if ($users->manager) {
                                return '<a title="' . $users->manager->fullName() . '" href="users/' . $users->manager->id . '/view">' . $users->manager->fullName() . '</a>';
                            }
                        })
                        ->addColumn('location', function($users) {
                            if ($users->userloc) {
                                return $users->userloc->name;
                            }
                        })
                        ->addColumn('assets', function($users) {
                            return $users->assets->count();
                        })
                        ->addColumn('licenses', function($users) {
                            return $users->licenses->count();
                        })
                        ->addColumn('accessories', function($users) {
                            return $users->accessories->count();
                        })
                        ->addColumn('consumables', function($users) {
                            return $users->consumables->count();
                        })
                        ->addColumn('groups', function($users) {
                            $group_names = '';
                            foreach ($users->groups as $group) {
                                $group_names .= '<a href="' . Config::get('app.url') . '/admin/groups/' . $group->id . '/edit" class="label  label-default">' . $group->name . '</a> ';
                            }
                            return $group_names;
                        })
                        ->addColumn($actions)
                        ->searchColumns('name', 'email', 'manager', 'activated', 'groups', 'location')
                        ->orderColumns('name', 'email', 'manager', 'activated', 'licenses', 'assets', 'accessories', 'consumables', 'groups', 'location')
                        ->make();
    }

    /**
     *  Upload the file to the server
     *
     * @param  int  $assetId
     * @return View
     * */
    public function postUpload($userId = null) {
        $user = User::find($userId);

        // the license is valid
        $destinationPath = app_path() . '/private_uploads';

        if (isset($user->id)) {

            if (Input::hasFile('userfile')) {

                foreach (Input::file('userfile') as $file) {

                    $rules = array(
                        'userfile' => 'required|mimes:png,gif,jpg,jpeg,doc,docx,pdf,txt,zip,rar|max:2000'
                    );
                    $validator = Validator::make(array('userfile' => $file), $rules);

                    if ($validator->passes()) {

                        $extension = $file->getClientOriginalExtension();
                        $filename = 'user-' . $user->id . '-' . str_random(8);
                        $filename .= '-' . Str::slug($file->getClientOriginalName()) . '.' . $extension;
                        $upload_success = $file->move($destinationPath, $filename);

                        //Log the deletion of seats to the log
                        $logaction = new Actionlog();
                        $logaction->asset_id = $user->id;
                        $logaction->asset_type = 'user';
                        $logaction->user_id = Sentry::getUser()->id;
                        $logaction->note = e(Input::get('notes'));
                        $logaction->checkedout_to = NULL;
                        $logaction->created_at = date("Y-m-d h:i:s");
                        $logaction->filename = $filename;
                        $log = $logaction->logaction('uploaded');
                    } else {
                        return Redirect::back()->with('error', Lang::get('admin/users/message.upload.invalidfiles'));
                    }
                }

                if ($upload_success) {
                    return Redirect::back()->with('success', Lang::get('admin/users/message.upload.success'));
                } else {
                    return Redirect::back()->with('error', Lang::get('admin/users/message.upload.error'));
                }
            } else {
                return Redirect::back()->with('error', Lang::get('admin/users/message.upload.nofiles'));
            }
        } else {
            // Prepare the error message
            $error = Lang::get('admin/users/message.does_not_exist', compact('id'));

            // Redirect to the licence management page
            return Redirect::route('users')->with('error', $error);
        }
    }

    /**
     *  Delete the associated file
     *
     * @param  int  $assetId
     * @return View
     * */
    public function getDeleteFile($userId = null, $fileId = null) {
        $user = User::find($userId);
        $destinationPath = app_path() . '/private_uploads';

        // the license is valid
        if (isset($user->id)) {

            $log = Actionlog::find($fileId);
            $full_filename = $destinationPath . '/' . $log->filename;
            if (file_exists($full_filename)) {
                unlink($destinationPath . '/' . $log->filename);
            }
            $log->delete();
            return Redirect::back()->with('success', Lang::get('admin/users/message.deletefile.success'));
        } else {
            // Prepare the error message
            $error = Lang::get('admin/users/message.does_not_exist', compact('id'));

            // Redirect to the licence management page
            return Redirect::route('users')->with('error', $error);
        }
    }

    /**
     *  Display/download the uploaded file
     *
     * @param  int  $assetId
     * @return View
     * */
    public function displayFile($userId = null, $fileId = null) {

        $user = User::find($userId);

        // the license is valid
        if (isset($user->id)) {
            $log = Actionlog::find($fileId);
            $file = $log->get_src();
            return Response::download($file);
        } else {
            // Prepare the error message
            $error = Lang::get('admin/users/message.does_not_exist', compact('id'));

            // Redirect to the licence management page
            return Redirect::route('users')->with('error', $error);
        }
    }

    /**
     * LDAP import
     *
     * @author Aladin Alaily
     * @return View
     */
    public function getLDAP() {
        // Get all the available groups
        $groups = Sentry::getGroupProvider()->findAll();
        // Selected groups
        $selectedGroups = Input::old('groups', array());
        // Get all the available permissions
        $permissions = Config::get('permissions');
        $this->encodeAllPermissions($permissions);
        // Selected permissions
        $selectedPermissions = Input::old('permissions', array('superuser' => -1));
        $this->encodePermissions($selectedPermissions);
        // Show the page
        return View::make('backend/users/ldap', compact('groups', 'selectedGroups', 'permissions', 'selectedPermissions'));
    }

    /**
     * Declare the rules for the form validation
     *
     * @var array
     */
    protected $ldapValidationRules = array(
        'firstname' => 'required|alpha_space|min:2',
        'lastname' => 'required|alpha_space|min:2',
        'pycyin' => 'numeric',
        'username' => 'required|min:2|unique:users,username',
        'mail' => 'email|unique:users,email',
    );

    /**
     * LDAP form processing.
     *
     * @Auther Aldin Alaily
     * @return Redirect
     */
    public function postLDAP() {

        $url = Config::get('ldap.url');
        $username = Config::get('ldap.username');
        $password = Config::get('ldap.password');
        $base_dn = Config::get('ldap.basedn');
        $filter = Config::get('ldap.filter');

        $ldapconn = ldap_connect($url)
                or die("Could not connect to LDAP server.");

        // Binding to ldap server
        $ldapbind = ldap_bind($ldapconn, $username, $password)
                or die("could not bind.");

        // Perform the search
        $search_results = ldap_search($ldapconn, $base_dn, $filter);
        $results = ldap_get_entries($ldapconn, $search_results);

        $summary = array();
        for ($i = 0; $i < $results["count"]; $i++) {
            if ($results[$i]["pyactive"][0] == "TRUE") {

                $item = array();
                $item["username"] = $results[$i]["pyusername"][0];
                $item["pycyin"] = $results[$i]["pycyin"][0];
                $item["cn"] = $results[$i]["cn"][0];
                $item["lastname"] = $results[$i]["sn"][0];
                $item["firstname"] = $results[$i]["givenname"][0];
                $item["mail"] = $results[$i]["mail"][0];

                $user = DB::table('users')->where('username', $item["username"])->first();
                if ($user) {
                    $item["note"] = "<strong>exists</strong>";
                } else {



                    $validator = Validator::make($item, $this->ldapValidationRules);
                    if ($validator->fails()) {
                        $item["note"] = "Validator failed: " . $validator->messages();
                    } else {

                        // Create the user if they don't exist.
                        $pass = substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 10);

                        $newuser = array(
                            'first_name' => $item["firstname"],
                            'last_name' => $item["lastname"],
                            'username' => $item["username"],
                            'email' => $item["mail"],
                            'employee_num' => $item["pycyin"],
                            'password' => $pass,
                            'activated' => 1,
                            'location_id' => 1,
                            'permissions' => '{"user":1}',
                            'notes' => 'Imported from LDAP'
                        );

                        DB::table('users')->insert($newuser);

                        $updateuser = Sentry::findUserByLogin($item["username"]);

                        // Update the user details
                        $updateuser->password = $pass;

                        // Update the user
                        $updateuser->save();

                        $item["note"] = "<strong>created</strong>";
                    } // Validator didn't fail
                }


                array_push($summary, $item);
            }
            /* Easy break in the loop */
            if ($i >= 15)
                break;
        }



        return Redirect::route('ldap/user')->with('success', "OK")->with('summary', $summary);
    }

}
