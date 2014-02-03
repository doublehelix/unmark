<?php
if (! defined('BASEPATH'))
    exit('No direct script access allowed');

class Tools extends Plain_Controller
{
    // NO API ROUTE
    public function __construct()
    {
        parent::__construct();
        parent::redirectIfNotAJAX();
    }

    /**
     * Generate password recovery token
     * AJAX action
     */
    public function forgotPassword()
    {
        $email = isset($this->db_clean->email) ? $this->db_clean->email : null;
        $validationResult = validate(array('email'=>$email), array('email'=>'email'), array('email'));
        if ($validationResult === true) {
            
            // Check if email exists in our DB
            $this->load->model('users_model', 'user');
            $user = $this->user->read('email = \'' . $email . '\' AND active = \'1\'');
            // Email does not exist or user is not active
            if (empty($user) || empty($user->user_id)) {
                $this->data['errors'] = formatErrors(90);
                // Valid email - generate token
            } else {
                $this->load->model('tokens_model', 'token');
                $createdToken = $this->token->create(array(
                    'token_type' => Tokens_model::TYPE_FORGOT_PASSWORD,
                    'user_id' => $user->user_id
                ));
                $this->data['token'] = array(
                    'token_value' => $createdToken->token_value,
                    'valid_until' => $createdToken->valid_until
                );
                // Invalidate all other tokens for this type and user
                $this->token->update("token_value != '{$createdToken->token_value}' and token_type = '{$createdToken->token_type}' and active='1' and user_id='{$createdToken->user_id}'", array(
                    'active' => 0
                ));
                // Prepare recovery link - {URL_BASE}/password_reset/{TOKEN}
                $urlTemplate = $this->config->item('forgot_password_recovery_url');
                $urlBase = $this->config->item('base_url');
                if(empty($urlBase)){
                    $urlBase = $_SERVER['HOST'];
                }
                $find = array('{URL_BASE}', '{TOKEN}');
                $replace = array($urlBase, $createdToken->token_value);
                $finalUrl  = str_replace($find, $replace, $urlTemplate);
                // Send email
                $this->load->library('plain_email', null, 'email');
                $emailConfig = $this->config->item('plain_email_settings');
                $this->email->initialize($emailConfig);
                
                $this->data['success'] = $this->email->resetPassword($user->email, $finalUrl);
            }
        } else {
            $this->data['errors'] = $validationResult;
        }
        $this->figureView();
    }

    /**
     * Verify if given token is valid
     * AJAX action
     */
    public function verifyToken()
    {
        $this->data['token_valid'] = false;
        $token = isset($this->db_clean->token) ? $this->db_clean->token : null;
        $validationResult = validate(array('token'=>$token), array('token'=>'string'), array('token'));
        if ($validationResult === true) {
            $this->load->model('tokens_model', 'token');
            if ($this->token->isValid($token)) {
                $this->data['token_valid'] = true;
            }
        } else {
            $this->data['errors'] = $validationResult;
        }
        $this->figureView();
    }

    /**
     * Reset users password
     */
    public function resetPassword()
    {
        $this->data['success'] = false;
        $token = isset($this->db_clean->token) ? $this->db_clean->token : null;
        $password = isset($this->clean->password) ? $this->clean->password : null;
        $validationResult = validate(array('token' => $token, 'password' => $password), array('token'=>'string', 'password' => 'password'), array('token', 'password'));
        if($validationResult === true){
            // Checking token
            $this->load->model('tokens_model', 'token');
            $tokenData = $this->token->read("token_value = '$token'");
            if (! $this->token->isValid($tokenData)) {
                $this->data['errors'] = formatErrors(91);
            } else {
                  $hashedPassword = generateHash($this->clean->password);
                  $this->load->model('users_model', 'user');
                  $user = $this->user->update($tokenData->user_id, array(
                      'password' => $hashedPassword
                  ));
                  if (isset($user->password) && $user->password == $hashedPassword) {
                      // Mark token as used
                      if (! $this->token->useToken($token)) {
                          log_message('DEBUG', 'Failed to mark token ' . $token . ' as used in DB');
                      }
                      $this->data['success'] = true;
                  } else {
                      $this->data['errors'] = formatErrors(601);
                  }
            }
        } else{
            $this->data['errors'] = $validationResult;
        }
        $this->figureView();
    }
}