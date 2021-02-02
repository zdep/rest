<?php
/*
	Текст задания в task.txt
	Slim -	https://www.slimframework.com/
			https://www.slimframework.ru/v3

	Для простоты разместил весь код в одном файле
	в продакшене, конечно, всё будет в своих каталогах.
*/

	use \Psr\Http\Message\ServerRequestInterface as Request;
	use \Psr\Http\Message\ResponseInterface as Response;

	require '../vendor/autoload.php';

	// интерфейс, чтобы не забывать добавлять методы в другие классы
	interface DatabaseConn
	{
		// crud
		public function create(array $data): array;
		public function read(int $id = 0): array;
		public function update(int $id, array $fields): array;
		public function delete(int $id): array;
	}

	// **************************************************************
	// "типа адаптер" для сохранения
	// выходит за рамки задания, но, мне кажется, лучше с ним :)
	class DB
	{
		public $definedNotFound = ['data' => ['error' => 'Not Found'], 'code' => 404];

		// **********************************************************
		public function connect(string $conn) {
			$class = $conn.'Connection';
			if (class_exists($class)) {
				return new $class;
			}
			else {
				new MyEx('System error!');
			}
		}

		// **********************************************************
		// абстракция для любого ответа
		public function returnOk(array $data, int $code): array {
			return ['data' => $data, 'code' => $code];
		}

		// **********************************************************
		// абстракция для неудачного ответа
		public function returnError(string $data, int $code): array {
			return $this->returnOk(['error' => $data], $code);
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
		public function create(array $data): array {
			++$this->data['users_inc'];
			$this->data['users'][] = array_merge(['id' => $this->data['users_inc']], $data);
			$this->saveAllData();
			return $this->returnOk(['id' => $this->data['users_inc']], 201);
		}

		// **********************************************************
		// возвращает одного юзера по ID или ошибку, если не найден
		public function read(int $id = 0): array {
			if ($id) {
				$found = $this->searchById($id);
				if ($found === false)
					return $this->definedNotFound;
				else
					return $this->returnOk($this->data['users'][$found], 200);
			}
			else
				return $this->returnOk($this->data['users'], 200);
		}

		// **********************************************************
		// апдейт юзера или ошибка, если не найден
		public function update(int $id, array $fields): array {
			if ($id) {
				$found = $this->searchById($id);
				if ($found !== false)
				{
					$this->data['users'][$found] = 
						array_merge($this->data['users'][$found], $fields);
					$this->saveAllData();

					return $this->returnOk(['status' => 'Ok'], 200);
				}
				else
					return $this->definedNotFound;
			}
			else
				return $this->definedNotFound;
		}

		// **********************************************************
		// удаление юзера или ошибку, если не найден
		public function delete(int $id): array {
			if ($id) {
				$found = $this->searchById($id);
				if ($found !== false)
				{
					unset($this->data['users'][$found]);
					$this->saveAllData();
					return $this->returnOk(['status' => 'Ok'], 202);
				}
				else
					return $this->definedNotFound;
			}
			else
				return $this->definedNotFound;
		}

		// **********************************************************
		// поиск юзера, возвращает массив или false, если не найден
		public function searchById(int $id) { // : array|bool только в php 8
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
	// реализован только коннект и вывод юзеров, остальные методы выдают "not found"
	class MysqlConnection extends DB implements DatabaseConn
	{
		private $dbh;

		// **********************************************************
		public function __construct() {
			try {
				$this->dbh = new PDO(
					'mysql:host=localhost;dbname=testapi;charset=utf8',
					'root',
					'root'
				);
			} catch (PDOException $e) {
				exit(json_encode([
					'error' => str_replace(['"', PHP_EOL], ['&quot;', '\n'], $e->getMessage())
				]));
			}
		}

		// **********************************************************
		public function create(array $data): array { return $this->definedNotFound; }

		// **********************************************************
		public function read(int $id = 0): array {
			if ($id) {
				$db = $this->dbh->prepare('select * from users where id = :id');
				$db->bindParam(':id', $id);
			} else {
				$db = $this->dbh->prepare('select * from users');
			}

			$db->execute();

			// на всякий проверим на ошибки
			$err = $db->errorInfo();
			if (isset($err[2]) && !is_null($err[2]))
				return $this->returnError($err[2], 409);

			$data = $db->fetchAll(PDO::FETCH_ASSOC);
			if (count($data))
			{
				// если найденный элемент один, то выводим сразу, без массива
				if (count($data) == 1)
					return $this->returnOk(reset($data), 200);
				else
					return $this->returnOk($data, 200);
			}
			else
				return $this->definedNotFound;
		}

		// **********************************************************
		public function update(int $id, array $fields): array { return $this->definedNotFound; }

		// **********************************************************
		public function delete(int $id): array { return $this->definedNotFound; }
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
		private
			$conn,
			$allFields = ['name', 'email', 'age'],
			$requiredFields = ['name', 'email'];

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
		public function create(array $fields): array {
			// добавляет только те поля, которые описаны в getAllFields()
			$allFields = [];
			foreach ($this->getAllFields() as $v) {
				$allFields[$v] = isset($fields[$v]) ? $fields[$v] : '';
			}

			return $this->conn->create($allFields);
		}

		// **********************************************************
		public function read(int $id = 0): array {
			return $this->conn->read($id);
		}

		// **********************************************************
		public function update(int $id, array $fields): array {
			return $this->conn->update($id, $fields);
		}

		// **********************************************************
		public function delete(int $id): array {
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
		// доступно "file", подключение к другим БД, mysql, oracle и подобным (включая noSQL)
		// можно написать, используя классы MysqlConnection, OracleConnection
		// и т.п.
		//return new User((new DB())->connect('mysql'));
		return new User((new DB())->connect('file'));
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
			$ret = $this->user->read();
			return $response->withJson($ret['data'], $ret['code']);
		});

		// **************************************************************
		// возвращает одного юзера или 404
		$this->get('users/{id:\d+}', function ($request, $response, $args) {
			$ret = $this->user->read($request->getAttribute('id'));
			return $response->withJson($ret['data'], $ret['code']);
		});

		// **************************************************************
		// добавляет юзера
		$this->post('users', function ($request, $response, $args) {
			$ret = $this->user->create(requiredFields($this->user->getRequiredFields()));
			return $response->withJson($ret['data'], $ret['code']);
		});

		// **************************************************************
		// обновляет юзера
		$this->patch('users/{id:\d+}', function ($request, $response, $args) {
			$fields = allFields($this->user->getAllFields());
			$ret = $this->user->update($request->getAttribute('id'), $fields);
			return $response->withJson($ret['data'], $ret['code']);
		});

		// **************************************************************
		// удаляет юзера
		$this->delete('users/{id:\d+}', function ($request, $response, $args) {
			$ret = $this->user->delete($request->getAttribute('id'));
			return $response->withJson($ret['data'], $ret['code']);
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
	// была идея это сделать на основе mysql (те поля что NOT NULL и были бы обязательными),
	// но для mysql это можно сделать, а для файлов нет...
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
