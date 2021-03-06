<?php
/*
 * LiveStreet CMS
 * Copyright © 2013 OOO "ЛС-СОФТ"
 *
 * ------------------------------------------------------
 *
 * Official site: www.livestreetcms.com
 * Contact e-mail: office@livestreetcms.com
 *
 * GNU General Public License, version 2:
 * http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 * ------------------------------------------------------
 *
 * @link http://www.livestreetcms.com
 * @copyright 2013 OOO "ЛС-СОФТ"
 * @author Maxim Mzhelskiy <rus.engine@gmail.com>
 *
 */

/**
 * Модуль обработки хуков(hooks)
 * В различных местах кода могут быть определеные вызовы хуков, например:
 * <pre>
 * $this->Hook_Run('topic_edit_before', array('oTopic'=>$oTopic,'oBlog'=>$oBlog));
 * </pre>
 * Данный вызов "вешает" хук "topic_edit_before"
 * Чтобы повесить обработчик на этот хук, его нужно объявить, например, через файл в /classes/hooks/HookTest.class.php
 * <pre>
 * class HookTest extends Hook {
 *    // Регистрируем хуки (вешаем обработчики)
 *    public function RegisterHook() {
 *        $this->AddHook('topic_edit_before','TopicEdit');
 *    }
 *    // обработчик хука
 *    public function TopicEdit($aParams) {
 *        $oTopic=$aParams['oTopic'];
 *        $oTopic->setTitle('My title!');
 *    }
 * }
 * </pre>
 * В данном примере после редактирования топика заголовок у него поменяется на "My title!"
 *
 * Если хук объявлен в шаблоне, например,
 * <pre>
 * {hook run='html_head_end'}
 * </pre>
 * То к имени хука автоматически добаляется префикс "template_" и обработчик на него вешать нужно так:
 * <pre>
 * $this->AddHook('template_html_head_end','InjectHead');
 * </pre>
 *
 * Так же существуют блочные хуки, который объявляются в шаблонах так:
 * <pre>
 * {hookb run="registration_captcha"}
 * ... html ...
 * {/hookb}
 * </pre>
 * Они позволяют заменить содержимое между {hookb ..} {/hookb} или добавить к нему произвольный контент. К имени такого хука добавляется префикс "template_block_"
 * <pre>
 * class HookTest extends Hook {
 *    // Регистрируем хуки (вешаем обработчики)
 *    public function RegisterHook() {
 *        $this->AddHook('template_block_registration_captcha','MyCaptcha');
 *    }
 *    // обработчик хука
 *    public function MyCaptcha($aParams) {
 *        $sContent=$aParams['content'];
 *        return $sContent.'My captcha!';
 *    }
 * }
 * </pre>
 * В данном примере в конце вывода каптчи будет добавлено "My captcha!"
 * Обратите внимание, что в обработчик в параметре "content" передается исходное содержание блока.
 *
 * @package framework.modules
 * @since 1.0
 */
class ModuleHook extends Module
{
    /**
     * Содержит список хуков
     *
     * @var array( 'name' => array(
     *        array(
     *            'type' => 'module' | 'hook' | 'function',
     *            'callback' => 'callback_name',
     *            'priority'    => 1,
     *            'params' => array()
     *        ),
     *    ),
     * )
     */
    protected $aHooks = array();
    /**
     * Список хуков по регулярному выражению
     *
     * @var array
     */
    protected $aHooksPreg = array();
    /**
     * Список объектов обработки хуков, для их кеширования
     *
     * @var array
     */
    protected $aHooksObject = array();
    /**
     * Список хуков для поведений - в них есть привязка к конкретному объекту
     *
     * @var array
     */
    protected $aHooksBehavior = array();

    /**
     * Инициализация модуля
     *
     */
    public function Init()
    {

    }

    /**
     * Добавление хука для поведения
     *
     * @param string $sName Имя хука
     * @param LsObject $oObject Объект которому принадлежит хук
     * @param array $aCallback Коллбек
     * @param int $iPriority Приоритет
     */
    public function AddHookBehavior($sName, $oObject, $aCallback, $iPriority = 1)
    {
        $sName = strtolower($sName);
        $sObjectHash = spl_object_hash($oObject);
        $this->aHooksBehavior[$sName][$sObjectHash][] = array('callback' => $aCallback, 'priority' => (int)$iPriority);
    }

    /**
     * Удаляет хук поведения
     *
     * @param string $sName Имя хука
     * @param LsObject $oObject Объект которому принадлежит хук
     * @param array|null $aCallback Коллбек, если не задан, то будут удалены все коллбеки
     *
     * @return bool
     */
    public function RemoveHookBehavior($sName, $oObject, $aCallback = null)
    {
        $sName = strtolower($sName);
        $sObjectHash = spl_object_hash($oObject);
        if (!isset($this->aHooksBehavior[$sName][$sObjectHash])) {
            return false;
        }
        if (is_null($aCallback)) {
            unset($this->aHooksBehavior[$sName][$sObjectHash]);
            return true;
        }
        $bRemoved = false;
        foreach ($this->aHooksBehavior[$sName][$sObjectHash] as $i => $aHook) {
            if ($aHook['callback'] === $aCallback) {
                unset($this->aHooksBehavior[$sName][$sObjectHash][$i]);
                $bRemoved = true;
            }
        }
        if ($bRemoved) {
            $this->aHooksBehavior[$sName][$sObjectHash] = array_values($this->aHooksBehavior[$sName][$sObjectHash]);
        }
        return $bRemoved;
    }

    /**
     * @param string $sName Имя хука
     * @param LsObject $oObject Объект которому принадлежит хук
     * @param array $aVars Параметры хука. Конкретные параметры можно передавать по ссылке, например, array('bResult'=>&$bResult)
     * @param bool $bWithGlobal Запускать дополнительно одноименный глобальный (стандартный) хук
     *
     * @return array
     */
    public function RunHookBehavior($sName, $oObject, $aVars = array(), $bWithGlobal = false)
    {
        $result = array();
        $sName = strtolower($sName);
        $sObjectHash = spl_object_hash($oObject);
        if (isset($this->aHooksBehavior[$sName][$sObjectHash])) {
            $aHooks = array();
            for ($i = 0; $i < count($this->aHooksBehavior[$sName][$sObjectHash]); $i++) {
                $aHooks[$i] = $this->aHooksBehavior[$sName][$sObjectHash][$i]['priority'];
            }
            arsort($aHooks, SORT_NUMERIC);
            /**
             * Сначала запускаем на выполнение
             */

            foreach ($aHooks as $iKey => $iPr) {
                $aHook = $this->aHooksBehavior[$sName][$sObjectHash][$iKey];
                $aHook['type'] = 'callback';
                $this->RunType($aHook, $aVars, $sName);
            }
        }
        if ($bWithGlobal) {
            $this->Run($sName, $aVars);
        }
        return $result;
    }

    /**
     * Добавление обработчика на хук
     *
     * @param string $sName Имя хука
     * @param string $sType Тип хука, возможны: module, function, hook
     * @param string $sCallBack Функция/метод обработки хука
     * @param int $iPriority Приоритер обработки, чем выше, тем раньше сработает хук относительно других
     * @param array $aParams Список дополнительных параметров, анпример, имя класса хука
     * @return bool
     */
    public function Add($sName, $sType, $sCallBack, $iPriority = 1, $aParams = array(), $bPreg = false)
    {
        $sName = strtolower($sName);
        $sType = strtolower($sType);
        if (!in_array($sType, array('module', 'hook', 'function'))) {
            return false;
        }
        $aHook = array(
            'type'     => $sType,
            'callback' => $sCallBack,
            'params'   => $aParams,
            'priority' => (int)$iPriority
        );
        if (!$bPreg) {
            $this->aHooks[$sName][] = $aHook;
        } else {
            $this->aHooksPreg[$sName][] = $aHook;
        }
    }

    /**
     * Добавляет обработчик хука с типом "module"
     * Позволяет в качестве обработчика использовать метод модуля
     * @see Add
     *
     * @param string $sName Имя хука
     * @param string $sCallBack Полное имя метода обработки хука, например, "Mymodule_CallBack"
     * @param int $iPriority Приоритер обработки, чем выше, тем раньше сработает хук относительно других
     * @return bool
     */
    public function AddExecModule($sName, $sCallBack, $iPriority = 1, $bPreg = false)
    {
        return $this->Add($sName, 'module', $sCallBack, $iPriority, array(), $bPreg);
    }

    /**
     * Добавляет обработчик хука с типом "function"
     * Позволяет в качестве обработчика использовать функцию
     * @see Add
     *
     * @param string $sName Имя хука
     * @param string $sCallBack Функция обработки хука, например, "var_dump"
     * @param int $iPriority Приоритер обработки, чем выше, тем раньше сработает хук относительно других
     * @return bool
     */
    public function AddExecFunction($sName, $sCallBack, $iPriority = 1, $bPreg = false)
    {
        return $this->Add($sName, 'function', $sCallBack, $iPriority, array(), $bPreg);
    }

    /**
     * Добавляет обработчик хука с типом "hook"
     * Позволяет в качестве обработчика использовать метод хука(класса хука из каталога /classes/hooks/)
     * @see Add
     * @see Hook::AddHook
     *
     * @param string $sName Имя хука
     * @param string $sCallBack Метод хука, например, "InitAction"
     * @param int $iPriority Приоритер обработки, чем выше, тем раньше сработает хук относительно других
     * @param array $aParams Параметры
     * @return bool
     */
    public function AddExecHook($sName, $sCallBack, $iPriority = 1, $aParams = array(), $bPreg = false)
    {
        return $this->Add($sName, 'hook', $sCallBack, $iPriority, $aParams, $bPreg);
    }

    /**
     * Добавляет делегирующий обработчик хука с типом "module"
     * Делегирующий хук применяется для перекрытия метода модуля, результат хука возвращает вместо результата метода модуля
     * Позволяет в качестве обработчика использовать метод модуля
     * @see Add
     * @see Engine::_CallModule
     *
     * @param string $sName Имя хука
     * @param string $sCallBack Полное имя метода обработки хука, например, "Mymodule_CallBack"
     * @param int $iPriority Приоритер обработки, чем выше, тем раньше сработает хук относительно других
     * @return bool
     */
    public function AddDelegateModule($sName, $sCallBack, $iPriority = 1, $bPreg = false)
    {
        return $this->Add($sName, 'module', $sCallBack, $iPriority, array('delegate' => true), $bPreg);
    }

    /**
     * Добавляет делегирующий обработчик хука с типом "function"
     * Делегирующий хук применяется для перекрытия метода модуля, результат хука возвращает вместо результата метода модуля
     * Позволяет в качестве обработчика использовать функцию
     * @see Add
     *
     * @param string $sName Имя хука
     * @param string $sCallBack Функция обработки хука, например, "var_dump"
     * @param int $iPriority Приоритер обработки, чем выше, тем раньше сработает хук относительно других
     * @return bool
     */
    public function AddDelegateFunction($sName, $sCallBack, $iPriority = 1, $bPreg = false)
    {
        return $this->Add($sName, 'function', $sCallBack, $iPriority, array('delegate' => true), $bPreg);
    }

    /**
     * Добавляет делегирующий обработчик хука с типом "hook"
     * Делегирующий хук применяется для перекрытия метода модуля, результат хука возвращает вместо результата метода модуля
     * Позволяет в качестве обработчика использовать метод хука(класса хука из каталога /classes/hooks/)
     * @see Add
     * @see Hook::AddHook
     *
     * @param string $sName Имя хука
     * @param string $sCallBack Метод хука, например, "InitAction"
     * @param int $iPriority Приоритер обработки, чем выше, тем раньше сработает хук относительно других
     * @param array $aParams Параметры
     * @return bool
     */
    public function AddDelegateHook($sName, $sCallBack, $iPriority = 1, $aParams = array(), $bPreg = false)
    {
        $aParams['delegate'] = true;
        return $this->Add($sName, 'hook', $sCallBack, $iPriority, $aParams, $bPreg);
    }

    /**
     * Запускает обаботку хуков
     *
     * @param $sName    Имя хука
     * @param array $aVars Список параметров хука, передаются в обработчик
     * @return array
     */
    public function Run($sName, &$aVars = array())
    {
        $result = array();
        $sName = strtolower($sName);
        /**
         * Массив хуков для исполнения по имени
         */
        $aRunHooks = isset($this->aHooks[$sName]) ? $this->aHooks[$sName] : array();
        /**
         * Добавляем хуки по регулярке
         */
        foreach ((array)$this->aHooksPreg as $sHookPreg => $aHooks) {
            if (preg_match($sHookPreg, $sName, $aMatch)) {
                $aRunHooks = array_merge($aRunHooks, $aHooks);
            }
        }
        if ($aRunHooks) {
            $bTemplateHook = strpos($sName, 'template_') === 0 ? true : false;
            $aHookNum = array();
            $aHookNumDelegate = array();
            /**
             * Все хуки делим на обычные(exec) и делигирующие(delegate)
             */
            for ($i = 0; $i < count($aRunHooks); $i++) {
                if (isset($aRunHooks[$i]['params']['delegate']) and $aRunHooks[$i]['params']['delegate']) {
                    $aHookNumDelegate[$i] = $aRunHooks[$i]['priority'];
                } else {
                    $aHookNum[$i] = $aRunHooks[$i]['priority'];
                }
            }
            arsort($aHookNum, SORT_NUMERIC);
            arsort($aHookNumDelegate, SORT_NUMERIC);
            /**
             * Сначала запускаем на выполнение простые
             */
            foreach ($aHookNum as $iKey => $iPr) {
                $aHook = $aRunHooks[$iKey];
                if ($bTemplateHook) {
                    /**
                     * Если это шаблонных хук то сохраняем результат
                     */
                    $result['template_result'][] = $this->RunType($aHook, $aVars, $sName);
                } else {
                    $this->RunType($aHook, $aVars, $sName);
                }
            }
            /**
             * Теперь запускаем делигирующие
             * Делегирующий хук должен вернуть результат в формате:
             *
             */
            foreach ($aHookNumDelegate as $iKey => $iPr) {
                $aHook = $aRunHooks[$iKey];
                $result = array(
                    'delegate_result' => $this->RunType($aHook, $aVars, $sName)
                );
                /**
                 * На данный момент только один хук может быть делегирующим
                 */
                break;
            }
        }
        return $result;
    }

    /**
     * Запускает обработчик хука в зависимости от типа обработчика
     *
     * @param array $aHook Данные хука
     * @param array $aVars Параметры переданные в хук
     * @return mixed|null
     */
    protected function RunType($aHook, &$aVars, $sName)
    {
        $result = null;
        switch ($aHook['type']) {
            case 'callback':
                $result = call_user_func_array($aHook['callback'], array(&$aVars, $sName));
                break;
            case 'module':
                $result = call_user_func_array(array($this, $aHook['callback']), array(&$aVars, $sName));
                break;
            case 'function':
                $result = call_user_func_array($aHook['callback'], array(&$aVars, $sName));
                break;
            case 'hook':
                $sHookClass = isset($aHook['params']['sClassName']) ? $aHook['params']['sClassName'] : null;
                if ($sHookClass and class_exists($sHookClass)) {
                    if (isset($this->aHooksObject[$sHookClass])) {
                        $oHook = $this->aHooksObject[$sHookClass];
                    } else {
                        $oHook = new $sHookClass;
                        $this->aHooksObject[$sHookClass] = $oHook;
                    }
                    $result = call_user_func_array(array($oHook, $aHook['callback']), array(&$aVars, $sName));
                }
                break;
            default:
                break;
        }
        return $result;
    }
}
