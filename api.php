<?php
/*	
	Для простоты разместил весь код в одном файле
	в продакшене, конечно, всё будет в своих каталогах.
	Если используете апач, то необходимо еще создать файл .htaccess с примерным содержимым:

	AddDefaultCharset utf-8
	RewriteEngine On
	RewriteBase /
	RewriteCond %{REQUEST_FILENAME} !-f
	RewriteCond %{REQUEST_FILENAME} !-d
	RewriteRule (.*) index.php

	или другими способами (без апача) чтобы запускался всегда index.php
*/

	use \Psr\Http\Message\ServerRequestInterface as Request;
	use \Psr\Http\Message\ResponseInterface as Response;

	require '../vendor/autoload.php';

	// интерфейс, чтобы не забывать добавлять методы в другие классы
	interface DatabaseConn
	{
		public function add(array $data): array;
		public function showAll(): array;
		public function showById(int $id);
		public function update(int $id, array $fields);
		public function delete(int $id);
	}

	// **************************************************************
	// "типа адаптер" для сохранения
	// выходит за рамки задания, но, мне кажется, лучше с ним :)
	class DB
	{
		public function connect(string $conn) {
			return ($conn == 'local' 
				? new FileConnection
				: ($conn == 'mysql' ? new MysqlConnection() : new MyEx('System error!')));
		}
	}

	// **************************************************************
	// класс для работы с файлами
	class FileConnection extends DB implements DatabaseConn
	{
		private $filename, $data;

		// **********************************************************
		// выясняем есть ли файл и читаем его, иначе создаём "пустышку"
		// файл создаётся в каталоге выше, чтобы не быть доступным из веба
		public function __construct() {
			$real = realpath('..'.DS.'file.json');
			if ($real === false)
			{
				$cfg = [
					'auth' => [],		// было придумано для авторизации (комментарии ниже)
					'users_inc' => 0,	// автоинкремент
					'users' => [],		// сами данные
				];

				file_put_contents(realpath(__DIR__.DS.'..'.DS).DS.'file.json', json_encode($cfg));
				$real = realpath('..'.DS.'file.json');
				if ($real === false)
					throw new MyEx('System Error!!!');
			}

			$this->filename = $real;
			$this->loadAllData();
		}

		// **********************************************************
		// разом читает все данные
		private function loadAllData(): void {
			$file = file_get_contents($this->filename);
			if (isJSON($file))
				$this->data = json_decode($file, true);
			else
				throw new MyEx('Not JSON!');
		}

		// **********************************************************
		// разом записывает все данные
		private function saveAllData(): void {
			file_put_contents($this->filename, json_encode($this->data));
		}

		// **********************************************************
		// добавление юзера
		public function add(array $data): array {
			++$this->data['users_inc'];
			$this->data['users'][] = array_merge(['id' => $this->data['users_inc']], $data);
			$this->saveAllData();
			return ['id' => $this->data['users_inc']];
		}

		// **********************************************************
		// возвращает список всех юзеров
		public function showAll(): array {
			return $this->data['users'];
		}

		// **********************************************************
		// возвращает одного юзера по ID или 404, если не найден
		public function showById(int $id)/*: array|int только в php 8 */ {
			$found = $this->searchById($id);
			return $found === false ? 404 : $this->data['users'][$found];
		}

		// **********************************************************
		// апдейт юзера или 404, если не найден
		public function update(int $id, array $fields) {
			$found = $this->searchById($id);
			if ($found !== false)
			{
				$this->data['users'][$found] = 
					array_merge($this->data['users'][$found], $fields);
				$this->saveAllData();
				return ['status' => 'Ok'];
			}
			else
				return 404;
		}

		// **********************************************************
		// удаление юзера или 404, если не найден
		public function delete(int $id) {
			$found = $this->searchById($id);
			if ($found !== false)
			{
				unset($this->data['users'][$found]);
				$this->saveAllData();
				return ['status' => 'Ok'];
			}
			else
				return 404;
		}

		// **********************************************************
		// поиск юзера, возвращает массив или false, если не найден
		public function searchById(int $id) {
			foreach ($this->data['users'] as $k => $v) {
				if ($v['id'] == $id) {
					return $k;
				}
			}

			return false;
		}
	}

	// **************************************************************
	// mysql
	class MysqlConnection extends DB implements DatabaseConn
	{
		public function add(array $data): array {}
		public function showAll(): array {}
		public function showById(int $id) {}
		public function update(int $id, array $fields) {}
		public function delete(int $id) {}
	}

	// **************************************************************
	// хочу свой эксепшн :)
	class MyEx extends Exception
	{
		// **************************************************************
		public function __construct(string $t = '') {
			exit(json_encode([
				'error' => str_replace(['"', PHP_EOL], ['&quot;', '\n'], $t)
			]));
		}
	}

	// **************************************************************
	// сущность юзера
	class User
	{
		private $conn;
		private $allFields = ['name', 'email', 'age'];
		private $requiredFields = ['name', 'email'];

		// **************************************************************
		public function __construct(DatabaseConn $conn) {
			$this->conn = $conn;
		}

		// **************************************************************
		// возвращает массив всех полей юзера
		public function getAllFields(): array {
			return $this->allFields;
		}

		// **************************************************************
		// возвращает массив обязательных полей юзера
		public function getRequiredFields(): array {
			return $this->requiredFields;
		}

		// **************************************************************
		public function add(array $fields): array {
			// добавляет только те поля, которые описаны в getAllFields()
			$allFields = [];
			foreach ($this->getAllFields() as $v) {
				$allFields[$v] = isset($fields[$v]) ? $fields[$v] : '';
			}

			return $this->conn->add($allFields);
		}

		// **************************************************************
		public function showAll(): array {
			return $this->conn->showAll();
		}

		// **********************************************************
		public function showById(int $id) {
			return $this->conn->showById($id);
		}

		// **********************************************************
		public function update(int $id, array $fields) {
			return $this->conn->update($id, $fields);
		}

		// **********************************************************
		public function delete(int $id) {
			return $this->conn->delete($id);
		}
	}

	// разработка на windows и слэши в пути разные, делаем одинаковые линуксовые
	defined('DS') or define('DS', DIRECTORY_SEPARATOR);

	$c = new \Slim\Container;

	// почему-то Slim-у пофиг на контент-тайп, ну не работает у меня :(
	// header('Content-Type: application/json; charset=utf-8');
	// $response->withJson тоже не работает как надо
	// сам текст ошибки работает, а контент-тайп и статус не выставляет
	$c['notFoundHandler'] = function ($c) {
		return function ($request, $response) use ($c) {
			return $c['response']
				->withStatus(404)
				->withHeader('Content-Type', 'application/json')
				->withJson(['error' => 'Not Found']);
		};
	};

	$c['notAllowedHandler'] = function ($c) {
		return function ($request, $response, $methods) use ($c) {
			return $c['response']
				->withStatus(405)
				->withHeader('Allow', implode(', ', $methods))
				->withHeader('Content-type', 'application/json')
				->withJson(['error' => 'Method Not Allowed']);
		};
	};

	$app = new \Slim\App($c);

/*
	коннектимся к БД
	можно не использовать контейнер, а сделать примерно так:
	$user = new User((new DB())->connect('local'));
	$app->group('/api/v1/', function() use ($user){
		...
	и далее внутри группы использовать так же, 
	но раз придумали его, надо использовать :)
*/
	$container = $app->getContainer();
	$container['user'] = function ($container) {
		return new User((new DB())->connect('local'));
	};

	// группа роутов
	$app->group('/api/v1/', function() {

		// **************************************************************
		// РОУТЫ!
		// в задании не сказано, но должна быть авторизация, но т.к. не все заголовки
		// работают, то не могу прикрутить Bearer авторизацию...
		$this->post('auth', function ($request, $response, $args) {
			$fields = requiredFields(['email', 'password']);
			return $response->withJson(['error' => '!Auth'], 401);
		});

		// **************************************************************
		// список всех юзеров
		$this->get('users', function ($request, $response, $args) {
			return $response->withJson($this->user->showAll(), 200);
		});

		// **************************************************************
		// возвращает одного юзера или 404
		$this->get('users/{id:\d+}', function ($request, $response, $args) {
			$ret = $this->user->showById($request->getAttribute('id'));
			if ($ret === 404)
				return $response->withJson(['error' => 'Not found'], 404);
			else
				return $response->withJson($ret, 200);
		});

		// **************************************************************
		// добавляет юзера
		$this->post('users', function ($request, $response, $args) {
			$fields = requiredFields($this->user->getRequiredFields());
			return $response->withJson($this->user->add($fields), 201);
		});

		// **************************************************************
		// обновляет юзера
		$this->patch('users/{id:\d+}', function ($request, $response, $args) {
			$fields = allFields($this->user->getAllFields());
			$ret = $this->user->update($request->getAttribute('id'), $fields);
			return $ret === 404
				? $response->withJson(['error' => 'Not found'], 404)
				: $response->withJson($ret, 202);
		});

		// **************************************************************
		// удаляет юзера
		$this->delete('users/{id:\d+}', function ($request, $response, $args) {
			$ret = $this->user->delete($request->getAttribute('id'));
			return $ret === 404
				? $response->withJson(['error' => 'Not found'], 404)
				: $response->withJson($ret, 200);
		});
	});

	$app->run();




	// **************************************************************
	// несколько хелперов
	// **************************************************************

	// **************************************************************
	// это json?
	function isJSON(string $string): bool {
		return is_string($string)
			&& is_array(json_decode($string, true))
			&& (json_last_error() == JSON_ERROR_NONE)
			? true : false;
	}

	// **************************************************************
	// проверяем есть ли требуемые поля в запросе, но
	// возвращает массив всех полей
	function requiredFields(array $fields): array {
		$json = getRequestBody();

		foreach ($fields as $k => $v) {
			if (!array_key_exists($v, $json))
				throw new MyEx('Missing '.$v);
		}

		return $json;
	}

	// **************************************************************
	// проверяем есть ли разрешенные поля в запросе,
	// возвращает массив доступных полей
	function allFields(array $fields): array {
		$json = getRequestBody();

		foreach ($json as $k => $v) {
			if (!in_array($k, $fields))
				unset($json[$k]);
		}

		return $json;
	}

	// **************************************************************
	// возвращает массив полей из запроса или ошибку разбора json
	function getRequestBody(): array {
		$input = file_get_contents('php://input');
		if (isJSON($input))
			return json_decode($input, true);
		else
			throw new MyEx('Not JSON!');
	}
