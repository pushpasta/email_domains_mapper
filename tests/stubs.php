<?php

declare(strict_types=1);

if (!class_exists('TestRedirectException', false)) {
    class TestRedirectException extends RuntimeException
    {
        public array $redirect_to;

        public function __construct(array $redirect_to)
        {
            parent::__construct('redirected');
            $this->redirect_to = $redirect_to;
        }
    }
}

if (!class_exists('rcube_plugin', false)) {
    class rcube_plugin
    {
        public string $ID = '';
        public $api;
        private array $hooks = [];

        public function __construct($api = null)
        {
            $this->ID = static::class;
            $this->api = $api;
        }

        public function add_hook($hook, $callback): void
        {
            $this->hooks[$hook][] = $callback;
        }

        public function load_config($fname = 'config.inc.php'): void
        {
        }

        public function registered_hooks(): array
        {
            return $this->hooks;
        }
    }
}

if (!class_exists('TestConfig', false)) {
    class TestConfig
    {
        private array $values = [];

        public function get($key, $default = null)
        {
            return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
        }

        public function set($key, $value): void
        {
            $this->values[$key] = $value;
        }
    }
}

if (!class_exists('TestPlugins', false)) {
    class TestPlugins
    {
        private array $plugins = [];

        public function get_plugin($name)
        {
            return $this->plugins[$name] ?? null;
        }

        public function register(string $name): void
        {
            $this->plugins[$name] = new stdClass();
        }
    }
}

if (!class_exists('TestOutput', false)) {
    class TestOutput
    {
        public bool $ajax_call = false;
        public array $messages = [];
        public bool $sent = false;

        public function show_message($message, $type = 'notice'): void
        {
            $this->messages[] = ['message' => $message, 'type' => $type];
        }

        public function send(): void
        {
            $this->sent = true;
        }

        public function redirect($params): void
        {
            throw new TestRedirectException((array) $params);
        }
    }
}

if (!class_exists('rcmail', false)) {
    class rcmail
    {
        private static $instance = null;
        public $config;
        public $plugins;
        public $output;

        private function __construct()
        {
            $this->config = new TestConfig();
            $this->plugins = new TestPlugins();
            $this->output = new TestOutput();
        }

        public static function get_instance()
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        public static function reset_instance(): void
        {
            self::$instance = null;
        }
    }
}

if (!class_exists('rcube', false)) {
    class rcube
    {
        public static array $log_lines = [];

        public static function write_log($name, $message): void
        {
            self::$log_lines[] = [(string) $name, (string) $message];
        }

        public static function reset_logs(): void
        {
            self::$log_lines = [];
        }
    }
}

if (!class_exists('rcube_utils', false)) {
    class rcube_utils
    {
        public static function idn_to_ascii($str)
        {
            if ($at = strpos($str, '@')) {
                $user = substr($str, 0, $at);
                $domain = substr($str, $at + 1);
            } else {
                $user = '';
                $domain = $str;
            }

            if (preg_match('/[^\x20-\x7E]/', $domain)) {
                $domain = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            }

            if ($domain === false) {
                return '';
            }

            return $at ? $user . '@' . $domain : $domain;
        }
    }
}
