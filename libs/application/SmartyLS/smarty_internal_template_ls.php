<?php

class Smarty_Internal_Template_LS extends Smarty_Internal_Template
{

    /**
     * Create template data object
     *
     * Some of the global Smarty settings copied to template scope
     * It load the required template resources and cacher plugins
     *
     * @param string $template_resource template resource string
     * @param Smarty $smarty Smarty instance
     * @param Smarty_Internal_Template $_parent back pointer to parent object with variables or null
     * @param mixed $_cache_id cache   id or null
     * @param mixed $_compile_id compile id or null
     * @param bool $_caching use caching?
     * @param int $_cache_lifetime cache life-time in seconds
     */
    public function __construct(
        $template_resource,
        $smarty,
        $_parent = null,
        $_cache_id = null,
        $_compile_id = null,
        $_caching = null,
        $_cache_lifetime = null
    ) {
        $bSkipDelegate = false;
        if (preg_match('#^Inherit@(.+)#i', $template_resource, $aMatch)) {
            /**
             * Получаем шаблон по цепочке наследования
             */
            $sTpl = trim($aMatch[1]);
            $sParentTemplate = Engine::getInstance()->Plugin_GetParentInherit($sTpl);
            if ($sTpl == $sParentTemplate) {
                /**
                 * Сбрасываем цепочку наследования к начальному состоянию
                 */
                Engine::getInstance()->Plugin_ResetInheritPosition($sParentTemplate);
                /**
                 * В параметре может быть путь до шаблона с ".tpl" в конце, а таже может быть путь до шаблона компонента вида "component.name.template"
                 */
                if (!preg_match("#^\.tpl$#i", $sTpl)) {
                    $aPath = explode('.', $sTpl);
                    if (count($aPath) == 3 and preg_match('#^([\w\_]+\:)?component$#i', $aPath[0], $aMatch2)) {
                        $sPrefix = '';
                        if (isset($aMatch2[1])) {
                            $sPrefix = $aMatch2[1];
                        }
                        $sParentTemplate = Engine::getInstance()->Component_GetTemplatePath($sPrefix . $aPath[1],
                            $aPath[2], false);
                    }
                }
            }
            $template_resource = $sParentTemplate;
            $bSkipDelegate = true;
        }
        /*
         * Ловим использование extends на файлы компонентов
         * Синтаксис: {extends 'Component@compname.template'}
         */
        if (preg_match('#^Component@(.+)#i', $template_resource, $aMatch)) {
            $aPath = explode('.', $aMatch[1], 2);
            $template_resource = Engine::getInstance()->Component_GetTemplatePath($aPath[0], isset($aPath[1]) ? $aPath[1] : null, true);
            $bSkipDelegate = true;
        }

        if (!$bSkipDelegate) {
            $template_resource = Engine::getInstance()->Plugin_GetDelegate('template', $template_resource);
        }

        parent::__construct($template_resource, $smarty, $_parent, $_cache_id, $_compile_id, $_caching,
            $_cache_lifetime);
    }
}