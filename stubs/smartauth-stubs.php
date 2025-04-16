<?php
/** Generated stub declarations for SmartAuth. - @see https://cap-rel.fr */

namespace SmartAuth\Api;

class AuthController
{
    /**
     * @api {get} /login List of dolibarr entities
     * @apiName GetLogin
     * @apiGroup Auth
     *
     * @apiSuccess {Array} entities array of dolibarr available entities
     *
     * @apiDescription Get the list of dolibarr entities before login
     * then you can make a login request on the right dolibarr entity
     * if your dolibarr use multicompany module
     */
    public function index($arr = null)
    {
    }
    /**
     * @api {post} /login Login
     *
     * @apiDescription Try to log into dolibarr with login / password and
     * in case of success generate a token for that app / session
     *
     * @apiName PostLogin
     * @apiGroup Auth
     *
     * @apiBody (Login) {String} email     Mandatory dolibarr user name (email)
     * @apiBody (Login) {String} password  Mandatory user password
     * @apiBody (Login) {Number} entity    Mandatory dolibarr entity
     *
     * @apiSuccess {String} user      User login
     * @apiSuccess {Number} userid    User ID
     * @apiSuccess {String} token     Session JWT to use for next requests as Bearer Auth Token (JWT)
     *
     * @apiSuccessExample {json} Success-Response:
     * HTTP/1.1 200 OK
     * {
     *     "statusCode": 200,
     *     "data": {
     *         "user": "eric@cap-rel.fr",
     *         "userid": "3",
     *         "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUz88NiJ9.eyJsb2dpbiI622RsYyIsImVu88l0eSI6MH0._XWcHLf999kMqkP65dgXcbkqT522W9zbdUiIA3BU0pI"
     *     }
     *  }
     */
    public function login($payload)
    {
    }
    /**
     * @api {post} /logout Logout
     * @apiDescription Logout and close session
     * @apiName PostLogout
     * @apiGroup Auth
     *
     */
    public function logout($payload)
    {
    }
    /**
     * check if token is correct
     *
     * @return  StdObject  decoded token
     */
    public static function check()
    {
    }
    /**
     * create a new salt stored into database and a key
     *
     * @param   [type]  $uid     [$uid description]
     * @param   [type]  $entity  [$entity description]
     *
     * @return  [type]           [return description]
     */
    private function _newUserKey($uid, $login, $entity)
    {
    }
    /**
     * create a new salt stored into database and a key for thirdpart account
     *
     * @param   [type]  $uid     [$uid description]
     * @param   [type]  $entity  [$entity description]
     *
     * @return  [type]           [return description]
     */
    public function newThirdpartKey($socid, $socname, $entity)
    {
    }
    private static function getAuthorizationHeader()
    {
    }
    private static function getBearerToken()
    {
    }
    /**
     * return array with list of entities if multientity is enabled
     *
     * @return  [type]  [return description]
     */
    private function _api_GetListOfEntities()
    {
    }
    /**
     * fetch a dolibarr user and load its rights
     *
     * @param   [type]$u  [$u description]
     * @param   null      [ description]
     *
     * @return  [type]    [return description]
     */
    private function _FetchUserWithRights($u = null)
    {
    }
}
class RouteController
{
    public static function get($targetAction, $targetClass, $redirectFunction, $protected = false)
    {
    }
    public static function post($targetAction, $targetClass, $redirectFunction, $protected = false)
    {
    }
    public static function put($targetAction, $targetClass, $redirectFunction, $protected = false)
    {
    }
    /**
     * routage des appels sur l'api
     *
     * @param   [type]  $targetMethod      [$targetMethod description]
     * @param   [type]  $targetAction      [$targetAction description]
     * @param   [type]  $redirectFunction  [$redirectFunction description]
     * @param   [type]  $protected         [$protected description]
     *
     * @return  [type]                     [return description]
     */
    public static function route($targetMethod, $targetAction, $targetClass, $redirectFunction, $protected = false)
    {
    }
    private function _getAuthorizationHeader()
    {
    }
}