<?php
/*
=============================================================================
BlockPro 3 - Модуль для вывода блоков с новостями на страницах сайта DLE (тестировался на 9.7 и 9.8)
=============================================================================
Автор модуля: ПафНутиЙ 
URL: http://blockpro.ru/
ICQ: 817233 
email: pafnuty10@gmail.com
-----------------------------------------------------------------------------
Автор оригинальных методов: Александр Фомин
URL: http://mithrandir.ru/
email: mail@mithrandir.ru
-----------------------------------------------------------------------------
Первод в singleton и помошь по коду: nowheremany
URL: http://nowheredev.ru/
=============================================================================
Файл:  block.pro.3.php
-----------------------------------------------------------------------------
Версия: 3.2.2.1 (07.03.2013)
=============================================================================
*/ 

// Как всегда главная строка)))
if( ! defined( 'DATALIFEENGINE' ) ) {
	die( "Hacking attempt!" );
}

if($showstat) $start = microtime(true);
if(!class_exists('BlockPro')) {
	class BlockPro {
		protected static $_instance;
		// Конструктор конфига модуля
		private function __construct()
		{
			global $config;

			// Получаем конфиг DLE
			$this->dle_config = &$config;
		}
				
		public function __clone(){}
		private function __wakeup() {}
		
		/**
		* Статическая функция, которая возвращает
		* экземпляр класса или создает новый при
		* необходимости
		*
		* @return SingletonTest
		*/
		 public static function getInstance() 
		 {
			if (null === self::$_instance) {
		        	self::$_instance = new self();
		        }
		        return self::$_instance;
		}

		/*
		 * Новый конфиг
		 */
		public function set_config($cfg) 
		{
			// Задаем конфигуратор класса
			$this->config = $cfg;
		}

		/*
		 * Обновление даных
		 */
		public function get_category() 
		{
			global $category, $category_id;
			$this->category_id = $category_id;
			$this->category = $category;		
		}

		/*
		 * Главный метод класса BlockPro
		 */
		public function runBlockPro($BlockProConfig)
		{
		global $db, $cat_info, $lang;

			$this->get_category();
			$this->set_config($BlockProConfig);

			// Защита от фашистов )))) (НУЖНА ЛИ? )
			$this->config['postId']     = @$db->safesql(strip_tags(str_replace('/', '', $this->config['postId'])));
			$this->config['notPostId'] = @$db->safesql(strip_tags(str_replace('/', '', $this->config['notPostId'])));

			$this->config['author']      = @$db->safesql(strip_tags(str_replace('/', '', $this->config['author'])));
			$this->config['notAuthor']  = @$db->safesql(strip_tags(str_replace('/', '', $this->config['notAuthor'])));

			$this->config['xfilter']     = @$db->safesql(strip_tags(str_replace('/', '', $this->config['xfilter'])));
			$this->config['notXfilter'] = @$db->safesql(strip_tags(str_replace('/', '', $this->config['notXfilter'])));

			// Определяем сегодняшнюю дату
			$tooday = date("Y-m-d H:i:s", (time() + $this->dle_config['date_adjust'] * 60));
			// Проверка версии DLE
			if ($this->dle_config['version_id'] >= 9.6) $newVersion = true;
			
			
			// Пробуем подгрузить содержимое модуля из кэша
			$output = false;

			// Назначаем суффикс кеша, если имеются переменные со значениями this, иначе для разных мест будет создаваться один и тот же файл кеша
			$cache_suffix = '';

			if ($this->config['catId'] == 'this') $cache_suffix .= $this->category_id.'cId_';
			if ($this->config['notCatId'] == 'this') $cache_suffix .= $this->category_id.'nCId_';
			if ($this->config['postId'] == 'this') $cache_suffix .= $_REQUEST["newsid"].'pId_';
			if ($this->config['notPostId'] == 'this') $cache_suffix .= $_REQUEST["newsid"].'nPId_';
			if ($this->config['author'] == 'this') $cache_suffix .= $_REQUEST["user"].'a_';
			if ($this->config['notAuthor'] == 'this') $cache_suffix .= $_REQUEST["user"].'nA_';
			if ($this->config['related'] == 'this') $cache_suffix .= $_REQUEST["newsid"].'r_';


			// Если установлено время жизи кеша - убираем префикс news_ чтобы кеш не чистился автоматом
			// и задаём настройки времени жизни кеша в секундах
			if ($this->config['cacheLive']) 
			{
				$this->config['prefix'] = ''; 

				$filedate = ENGINE_DIR.'/cache/'.$this->config['prefix'].'bp_'.md5($cache_suffix.implode('_', $this->config)).'.tmp';

				if(@file_exists($filedate)) $cache_time=time()-@filemtime ($filedate);
				else $cache_time = $this->config['cacheLive']*60;	
				if ($cache_time>=$this->config['cacheLive']*60) $clear_time_cache = 1;
			}

			// Если nocache не установлен - добавляем префикс (по умолчанию news_) к файлу кеша. 
			if( !$this->config['nocache'])
			{
				$output = dle_cache($this->config['prefix'].'bp_'.md5($cache_suffix.implode('_', $this->config)));
			}
			if ($clear_time_cache) 
			{
				$output = false;
			}
			
			// Если значение кэша для данной конфигурации получено, выводим содержимое кэша
			if($output !== false)
			{
				$this->showOutput($output);
				return;
			}
			
			// Если в кэше ничего не найдено, генерируем модуль заново

			$wheres = array();


			// Условие для отображения только постов, прошедших модерацию
			$wheres[] = 'approve';

			// Фильтрация КАТЕГОРИЙ по их ID
			if ($this->config['catId'] == 'this') $this->config['catId'] = $this->category_id;
			if ($this->config['notCatId'] == 'this') $this->config['notCatId'] = $this->category_id;
			
			if ($this->config['catId'] || $this->config['notCatId']) 
			{
				$ignore = ($this->config['notCatId']) ? 'NOT ' : '';
				$catArr = ($this->config['notCatId']) ? $this->config['notCatId'] : $this->config['catId'];	
				
				$wheres[] = $ignore.'category regexp "[[:<:]]('.str_replace(',', '|', $catArr).')[[:>:]]"';				
			}

			// Фильтрация НОВОСТЕЙ по их ID
			if ($this->config['postId'] == 'this') $this->config['postId'] = $_REQUEST["newsid"];
			if ($this->config['notPostId'] == 'this') $this->config['notPostId'] = $_REQUEST["newsid"];

			if (($this->config['postId'] || $this->config['notPostId']) && $this->config['related'] == '') 
			{
				$ignorePosts = ($this->config['notPostId']) ? 'NOT ' : '';
				$postsArr = ($this->config['notPostId']) ? $this->config['notPostId'] : $this->config['postId'];					
				$wheres[] = $ignorePosts.'id regexp "[[:<:]]('.str_replace(',', '|', $postsArr).')[[:>:]]"';				
			}

			// Фильтрация новостей по АВТОРАМ
			if ($this->config['author'] == 'this') $this->config['author'] = $_REQUEST["user"];
			if ($this->config['notAuthor'] == 'this') $this->config['notAuthor'] = $_REQUEST["user"];

			if ($this->config['author'] || $this->config['notAuthor']) 
			{
				$ignoreAuthors = ($this->config['notAuthor']) ? 'NOT ' : '';
				$authorsArr = ($this->config['notAuthor']) ? $this->config['notAuthor'] : $this->config['author'];					
				$wheres[] = $ignoreAuthors.'autor regexp "[[:<:]]('.str_replace(',', '|', $authorsArr).')[[:>:]]"';				
			}

			// Фильтрация новостей по ДОПОЛНИТЕЛЬНЫМ ПОЛЯМ

			if ($this->config['xfilter'] || $this->config['notXfilter']) 
			{
				$ignoreXfilters = ($this->config['notXfilter']) ? 'NOT ' : '';
				$xfiltersArr = ($this->config['notXfilter']) ? $this->config['notXfilter'] : $this->config['xfilter'];					
				$wheres[] = $ignoreXfilters.'xfields regexp "[[:<:]]('.str_replace(',', '|', $xfiltersArr).')[[:>:]]"';				
			}

			// Если включен режим вывода похожих новостей:
			if ($this->config['related'] != '') 
			{
				if ($this->config['related'] == 'this' && $_REQUEST["newsid"] =='') {
					echo '<span style="color: red;">Переменная related=this работает только в полной новости.</span>';
					return;
				}
				$relatedId = ($this->config['related'] == 'this') ? $_REQUEST["newsid"] : $this->config['related'];
				$wheresRelated = array();				
				$relatedRows = 'title, short_story, full_story, xfields';
				$wheresRelated[] = 'approve';
				$wheresRelated[] = 'id = '.$relatedId;
				$whereRlated = implode(' AND ', $wheresRelated);

				$relatedBody = $this->load_table (PREFIX . '_post', $relatedRows, $whereRlated, false, '0', '1', '', '');

				$bodyToRelated = (strlen($relatedBody['full_story']) < strlen($relatedBody['short_story'])) ? $relatedBody['short_story'] : $relatedBody['full_story'];				
				$bodyToRelated = $db->safesql(strip_tags(stripslashes($relatedBody['title'] . " " . $bodyToRelated)));
				
				$wheres[] = 'MATCH ('.$relatedRows.') AGAINST ("'.$bodyToRelated.'") AND id !='.$relatedId;

			}


			// Разбираемся с временными рамками отбора новостей, если кол-во дней указано - ограничиваем выборку, если нет - выводим без ограничения даты
			if(intval($this->config['day'])) $wheres[] =  'date >= "'.$tooday.'" - INTERVAL ' .  intval($this->config['day']) . ' DAY';

			// Условие для отображения только тех постов, дата публикации которых уже наступила
			
			$wheres[] = "date < '{$tooday}'";
			
			// Складываем условия
			$where = implode(' AND ', $wheres);
			
			// Направление сортировки по убыванию или возрастанию
			$ordering = $this->config['order'] == 'new'?'DESC':'ASC';

			// Сортировка новостей 
			switch ($this->config['sort']) 
			{
				case 'none':					// Не сортировать (можно использовать для вывода похожих новостей, аналогично стандарту DLE)
					$sort = false; 			
					break;

				case 'date':					// Дата
					$sort = 'date '; 			
					break;

				case 'rating':					// Рейтинг
					$sort = 'rating ';			
					break;

				case 'comms':					// Комментарии
					$sort = 'comm_num ';
					break;

				case 'views':					// Просмотры
					$sort = 'news_read ';
					break;

				case 'random':					// Случайные
					$sort = 'RAND() ';
					break;

				case 'title':					// По алфавиту
					$sort = 'title ';
					break;
				
				default:						// Топ как в DLE (сортировка по умолчанию)
					$sort = 'rating '.$ordering.', comm_num '.$ordering.', news_read ';
					break;
			}
			

			// Формирование запроса в зависимости от версии движка
			if ($newVersion) {
				// 9.6 и выше
				$selectRows = 'p.id, p.autor, p.date, p.short_story, p.full_story, p.xfields, p.title, p.category, p.alt_name, p.allow_comm, p.comm_num, e.news_read, e.allow_rate, e.rating, e.vote_num, e.votes';
			} else {
				// старые версии идут лесом
				echo '<span style="color: #f00">Модуль поддерживает только DLE 9.6 и выше.</span>';
				return;
			}

			
			/**
			 * Service function - take params from table
			 * @param $table string - название таблицы
			 * @param $fields string - необходимые поля через запятйю или * для всех
			 * @param $where string - условие выборки
			 * @param $multirow bool - забирать ли один ряд или несколько
			 * @param $start int - начальное значение выборки
			 * @param $limit int - количество записей для выборки, 0 - выбрать все
			 * @param $sort string - поле, по которому осуществляется сортировка
			 * @param $sort_order - направление сортировки
			 * @return array с данными или false если mysql вернуль 0 рядов
			 */

			
			$news = $this->load_table (PREFIX . '_post p LEFT JOIN ' . PREFIX . '_post_extras e ON (p.id=e.news_id)', $selectRows, $where, true, $this->config['startFrom'], $this->config['limit'], $sort, $ordering);


			if(empty($news)) $news = array();

			// Задаём переменную, в котоую будем всё складывать
			$output = '';

			// Если в выборке нет новостей - сообщаем об этом
			if (empty($news)) {
				$output .= '<span style="color: #f00">По заданным критериям материалов нет, попробуйте изменить параметры строки подключения</span>';
				return;
			}
			// Пробегаем по массиву с новостями и формируем список
			foreach ($news as $newsItem) 
			{
				$xfields = xfieldsload();

				$newsItem['date'] = strtotime($newsItem['date']);
				$newsItem['short_story'] = stripslashes($newsItem['short_story']);
				$newsItem['full_story'] = stripslashes($newsItem['full_story']);


				// Формируем ссылки на категории и иконки категорий
				$my_cat = array();
				$my_cat_icon = array();
				$my_cat_link = array();
				$cat_list = explode(',', $newsItem['category']);
				foreach($cat_list as $element) {
					if(isset($cat_info[$element])) {
						$my_cat[] = $cat_info[$element]['name'];
						if ($cat_info[$element]['icon'])
							$my_cat_icon[] = '<img class="bp-cat-icon" src="'.$cat_info[$element]['icon'].'" alt="'.$cat_info[$element]['name'].'" />';
						else
							$my_cat_icon[] = '<img class="bp-cat-icon" src="{THEME}/blockpro/'.$this->config['noicon'].'" alt="'.$cat_info[$element]['name'].'" />';
						if( $this->dle_config['allow_alt_url'] == 'yes' ) 
							$my_cat_link[] = '<a href="'.$this->dle_config['http_home_url'].get_url($element).'/">'.$cat_info[$element]['name'].'</a>';
						else 
							$my_cat_link[] = '<a href="'.$PHP_SELF.'?do=cat&category='.$cat_info[$element]['alt_name'].'">'.$cat_info[$element]['name'].'</a>';
					}
				}
				$categoryUrl = ($newsItem['category']) ? $this->dle_config['http_home_url'] . get_url(intval($newsItem['category'])) . '/' : '/' ;

				// Ссылка на профиль  юзера
				if( $this->dle_config['allow_alt_url'] == 'yes' ) {
					$go_page = $config['http_home_url'].'user/'.urlencode($newsItem['autor']).'/';
				} else {
					$go_page = $PHP_SELF.'?subaction=userinfo&amp;user='.urlencode($newsItem['autor']);
				}

				// Выводим картинку
				switch($this->config['image'])
				{
					// Первое изображение из краткой новости
					case 'short_story':
						$imgArray = $this->getImage($newsItem['short_story'], $newsItem['date']);
						break;
					
					// Первое изображение из полного описания
					case 'full_story':
						$imgArray = $this->getImage($newsItem['full_story'], $newsItem['date']);
						break;
					
					// Изображение из дополнительного поля 
					default:
						$xfieldsdata = xfieldsdataload($newsItem['xfields']);
						// if(!empty($xfieldsdata) && !empty($xfieldsdata[$this->config['image']]))
						// {
							// $imgArray = getImage($xfieldsdata[$this->config['image']], $newsItem['date']);
							$imgArray = $this->getImage($xfieldsdata[$this->config['image']], $newsItem['date']);
							//$imgArray = $this->getImage($newsItem['short_story'], $newsItem['date']);
						// }
						break;
				}
				

				// Определяем переменные, выводящие картинку
				$image = ($imgArray['imgResized']) ? $imgArray['imgResized'] : '{THEME}/blockpro/'.$this->config['noimage'];
				if (!$imgArray['imgResized']) {
					$imageFull = '{THEME}/blockpro/'.$this->config['noimageFull'];
				} else {
					$imageFull = $imgArray['imgOriginal'];
				}

				// Формируем вид даты новости для вывода в шаблон
				if(date('Ymd', $newsItem['date']) == date('Ymd')) {
					$showDate = $lang['time_heute'].langdate(', H:i', $newsItem['date']);		
				} elseif(date('Ymd', $newsItem['date'])  == date('Ymd') - 1) {			
					$showDate = $lang['time_gestern'].langdate(', H:i', $newsItem['date']);		
				} else {			
					$showDate = langdate($this->dle_config['timestamp_active'], $newsItem['date']);		
				}

				/**
				 * Код, формирующий вывод шаблона новости
				 */

				// проверяем существует ли файл шаблона, если есть - работаем дальше
				if (file_exists(TEMPLATE_DIR.'/'.$this->config['template'].'.tpl')) 
				{

					$xfieldsdata = xfieldsdataload($newsItem['xfields']);

					$newsTitle = htmlspecialchars(strip_tags(stripslashes($newsItem['title'])), ENT_QUOTES, $this->dle_config['charset']);


					$output .= $this->applyTemplate($this->config['template'],
						array(
							'{title}'			=> $this->textLimit($newsTitle, $this->config['titleLimit']),
							'{full-title}'		=> $newsTitle,
							'{full-link}'		=> $this->getPostUrl($newsItem),
							'{image}'			=> $image,
							'{full-image}'		=> $imageFull,
							'{short-story}' 	=> $this->textLimit($newsItem['short_story'], $this->config['textLimit']),
	                    	'{full-story}'  	=> $this->textLimit($newsItem['full_story'], $this->config['textLimit']),
	                    	'{link-category}'	=> implode(', ', $my_cat_link),
							'{category}'		=> implode(', ', $my_cat),
							'{category-icon}'	=> implode('', $my_cat_icon),
							'{category-url}'	=> $categoryUrl,
							'{news-id}'			=> $newsItem['id'],
							'{author}'			=> "<a onclick=\"ShowProfile('" . urlencode( $newsItem['autor'] ) . "', '" . $go_page . "', '" . $user_group[$member_id['user_group']]['admin_editusers'] . "'); return false;\" href=\"" . $go_page . "\">" . $newsItem['autor'] . "</a>",
							'{login}'			=> $newsItem['autor'],
							'[profile]'			=> '<a href="'.$go_page.'">',
							'[/profile]'		=> '</a>',
							'[com-link]'		=> $newsItem['allow_comm']?'<a href="'.$this->getPostUrl($newsItem).'#comment">':'',
							'[/com-link]'		=> $newsItem['allow_comm']?'</a>':'',
							'{comments-num}'	=> $newsItem['allow_comm']?$newsItem['comm_num']:'',
							'{views}'			=> $newsItem['news_read'],
							'{date}'			=> $showDate,
							'{rating}'			=> $newsItem['allow_rate']?ShowRating( $newsItem['id'], $newsItem['rating'], $newsItem['vote_num'], 0 ):'', 
							'{vote-num}'		=> $newsItem['allow_rate']?$newsItem['vote_num']:'', 

						),
						array(

							"'\[comments\\](.*?)\[/comments\]'si"                     => $newsItem['comm_num']!=='0'?'\\1':'',
							"'\[not-comments\\](.*?)\[/not-comments\]'si"             => $newsItem['comm_num']=='0'?'\\1':'',
							"'\[rating\\](.*?)\[/rating\]'si"                         => $newsItem['allow_rate']?'\\1':'',
							"'\[allow-comments\\](.*?)\[/allow-comments\]'si"         => $newsItem['allow_comm']?'\\1':'',
							"'\[disallow-comments\\](.*?)\[/disallow-comments\]'si"   => !$newsItem['allow_comm']?'\\1':'',
						),
						array(
							// preg_tpl array
							"#\{date=(.+?)\}#ie"                  => "langdate('\\1', '{$newsItem['date']}')",
							//"#\{title limit=['\"](.+?)['\"]\}#is" => $this->textLimit($newsTitle, "\1").'\\1', // - что-то не воркает это дело. как то не правильно передаются данные, даже если пописать непосредственно внутри .

						),
						array(
							// srt_tpl array
							// пусть будет на всякий случай
							),
						//передаём методу applyTemplate переменную с допполями, т.к. их обработка осуществляется именно там.
						$xfieldsdata 
					);

				} else 
				{
					// Если файла шаблона нет - выведем ошибку, а не белый лист.
					$output = '<b style="color: red;">Отсутствует файл шаблона: '.$template.'.tpl</b>';
				}
			}

			// Cохраняем в кэш по данной конфигурации если nocache false
			if(!$this->config['nocache'])
			{
				create_cache($this->config['prefix'].'bp_'.md5($cache_suffix.implode('_', $this->config)), $output);
			}
			
			// Выводим содержимое модуля
			$this->showOutput($output);

			
		}
		
		/**
		 * Service function - take params from table
		 * @param $table string - название таблицы
		 * @param $fields string - необходимые поля через запятйю или * для всех
		 * @param $where string - условие выборки
		 * @param $multirow bool - забирать ли один ряд или несколько
		 * @param $start int - начальное значение выборки
		 * @param $limit int - количество записей для выборки, 0 - выбрать все
		 * @param $sort string - поле, по которому осуществляется сортировка
		 * @param $sort_order - направление сортировки
		 * @return array с данными или false если mysql вернуль 0 рядов
		 */
		public function load_table ($table, $fields = '*', $where = '1', $multirow = false, $start = 0, $limit = 0, $sort = '', $sort_order = 'desc')
		{
			global $db;
			
			if (!$table) return false;

			if ($sort!='') $where.= ' order by '.$sort.' '.$sort_order;
			if ($limit>0) $where.= ' limit '.$start.','.$limit;
			$q = $db->query('SELECT '.$fields.' from '.$table.' where '.$where);
			if ($multirow)
			{
				while ($row = $db->get_row($q))
				{
					$values[] = $row;
				}
			}
			else
			{
				$values = $db->get_row($q);
			}
			if (count($values)>0) return $values;
			
			return false;

		}

		/**
		 * @param $data - контент
		 * @param $length - максимальный размер возвращаемого контента
		 * 
		 * @return $data - обрезанный результат 
		 */
		public function textLimit($data, $count)
		{
			if ($this->config['textLimit'] != '0' || $this->config['titleLimit'] != '0') 
			{	
				$data = strip_tags($data, '<br>');
				$data = trim(str_replace( array('<br>','<br />'), ' ', $data));

				if($count && dle_strlen($data, $this->dle_config['charset'] ) > $count)
				{
					$data = dle_substr( $data, 0, $count, $this->dle_config['charset'] ). '&hellip;';					
					if( !$this->config['wordcut'] && ($word_pos = dle_strrpos( $data, ' ', $this->dle_config['charset'] )) ) 
						$data = dle_substr( $data, 0, $word_pos, $this->dle_config['charset'] ). '&hellip;';

				}
			}
			return $data;
		}

		/**
		 * @param $post - массив с информацией о статье
		 * @return array - URL`s уменьшенной картинки и оригинальной
		 * если картинка лежит на внешнем ресурсе и включен параметр remoteImages - выводится url внешней картинки 
		 * если картинка не обработалась - выводится пустота
		 */

		public function getImage($post, $date)
		{	
			// Проверяем откуда задан вывод картинки
			$xf_img = true;
			if ($this->config['image'] == 'short_story' || $this->config['image'] == 'full_story') {
				$xf_img = false;
			} 

			// Задаём папку для картинок
			$dir_prefix = $this->config['imgSize'].'/'.date("Y-m", $date).'/';

			$dir = ROOT_DIR . '/uploads/blockpro/'.$dir_prefix;
			
			if(preg_match_all('/<img(?:\\s[^<>]*?)?\\bsrc\\s*=\\s*(?|"([^"]*)"|\'([^\']*)\'|([^<>\'"\\s]*))[^<>]*>/i', $post, $m) || $xf_img) {
				
				// Адрес первой картинки в новости
				$url = ($xf_img) ? $post : $m[1][0];	
				
				//Выдёргиваем оригинал, на случай если уменьшить надо до размеров больше, чем thumb в новости									
				$imgOriginal = str_ireplace('/thumbs', '', $url); 	

				// Удаляем текущий домен из строки
				$urlShort = str_ireplace('http://'.$_SERVER['HTTP_HOST'], '', $imgOriginal);

				// Если http нет - работаем с картинкой, если есть http или смайлик/спойлер - пропускаем, такая картинка нам не пойдёт, вставим заглушку
				if (stripos($urlShort, 'http') === false && stripos($urlShort, 'dleimages') === false && stripos($urlShort, 'engine/data/emoticons') === false && $post != '') 
				{
					// Если Есть параметр imgSize и картинка лежит у нас на сервере - включаем обрезку картинок
					if ($this->config['imgSize'] && $urlShort) 
					{
						// Создаём и назначаем права, если нет таковых
						if(!is_dir($dir)){						
							@mkdir($dir, 0755, true);
							@chmod($dir, 0755);
						} 
						if(!chmod($dir, 0755)) {
							@chmod($dir, 0755);
						}

						// Подставляем корневю дирректорию, чтоб ресайзер понял что ему дают.
						$imgResized = ROOT_DIR . $urlShort;					
						
						// Определяем новое имя файла
						$fileName = $this->config['imgSize'].'_'.$this->config['resizeType'].'_'.strtolower(basename($imgResized)); 		

						// Если картинки нет - создаём её
						if(!file_exists($dir.$fileName)) 
						{ 
							// Разделяем высоту и ширину
							$imgSize = explode('x', $this->config['imgSize']); 	

							// Если указана только одна величина - присваиваем второй первую, будет квадрат для exact, auto и crop, иначе класс ресайза жестоко тупит, ожидая вторую переменную.
							if(count($imgSize) == '1') 
								$imgSize[1] = $imgSize[0];

							// Подрубаем НОРМАЛЬНЫЙ класс для картинок
							require_once ENGINE_DIR.'/modules/blockpro/resize_class.php'; 				
							$resizeImg = new resize($imgResized);
							$resizeImg -> resizeImage(						//создание уменьшенной копии
								$imgSize[0],
								$imgSize[1],
								$this->config['resizeType']					//Метод уменьшения (exact, portrait, landscape, auto, crop)
								); 
							$resizeImg -> saveImage($dir.$fileName, $this->config['imgQuality']); 		//Сохраняем картинку в папку /uploads/blockpro/[размер_уменьшенной_копии]/[месяц_создания новости]
						}					 									
						
						$imgResized = $this->dle_config['http_home_url'].'uploads/blockpro/'.$dir_prefix.$fileName;	
					}
					// Если параметра imgSize нет - отдаём оригинальную картинку
					else 
					{
						$imgResized = $urlShort;
					}
				} 

				// Если внешняя картинка - возвращаем её, при наличии перемнной remoteImages в строке подключения
				elseif (stripos($urlShort, 'http') !== false && $this->config['remoteImages']) {
					$imgResized = $urlShort;						
				}

				// Если remoteImages не указан - выдаём пустоту
				elseif (stripos($urlShort, 'http') !== false)
				{
					$imgResized = '';
					$imgOriginal = '';
				}

				// Нам нужен на выходе массив из двух картинок
				$data = array('imgResized' => $imgResized, 'imgOriginal' => $imgOriginal);				
				
				return $data;
			}
			
		}

		/**
		 * @param $post - массив с информацией о статье
		 * @return string URL для категории
		 */
		public function getPostUrl($post)
		{
			if($this->dle_config['allow_alt_url'] == 'yes')
			{
				if(
					($this->dle_config['version_id'] < 9.6 && $this->dle_config['seo_type'])
						||
					($this->dle_config['version_id'] >= 9.6 && ($this->dle_config['seo_type'] == 1 || $this->dle_config['seo_type'] == 2))
				)
				{
					if(intval($post['category']) && $this->dle_config['seo_type'] == 2)
					{
						$url = $this->dle_config['http_home_url'].get_url(intval($post['category'])).'/'.$post['id'].'-'.$post['alt_name'].'.html';
					}
					else
					{
						$url = $this->dle_config['http_home_url'].$post['id'].'-'.$post['alt_name'].'.html';
					}
				}
				else
				{
					$url = $this->dle_config['http_home_url'].date('Y/m/d/', strtotime($post['date'])).$post['alt_name'].'.html';
				}
			}
			else
			{
				$url = $this->dle_config['http_home_url'].'index.php?newsid='.$post['id'];
			}

			return $url;
		}
		/**
		 * Метод, формиующий вывод шаблона
		 * @param $template - имя шаблона
		 * @param array $vars - массив с тегами
		 * @param array $blocks - массив с блоками
		 * @param array $preg_tpl - массив для передачи в copy_template
		 * @param array $srt_tpl - массив для передачи в copy_template
		 * @param $xf_replace - дополнительные поля
		 * @return скомпилированный шаблон
		 */
		public function applyTemplate($template, $vars = array(), $blocks = array(), $preg_tpl = array(), $srt_tpl = array(), $xf_replace)
		{
		global $tpl;
			if(!isset($tpl)) {
				$tpl = new dle_template();
				$tpl->dir = TEMPLATE_DIR;
			} else {
				$tpl->result['blockPro'] = '';
			}
			// Подключаем файл шаблона $template.tpl, заполняем его
			$tpl->load_template($template.'.tpl');
			
			$tpl->set('', $vars);
				

			// Заполняем шаблон блоками
			foreach($blocks as $block => $value)
			{
				$tpl->set_block($block, $value);
			}

			// Заменяем  preg_replace в шаблоне
			foreach($preg_tpl as $copyTpl => $val)
			{
				$tpl->copy_template = preg_replace($copyTpl, $val, $tpl->copy_template);
			}

			// Заменяем  str_replace в шаблоне
			foreach($srt_tpl as $copyTpl => $val)
			{
				$tpl->copy_template = str_replace($copyTpl, $val, $tpl->copy_template);
			}


			// Обрабатываем допполя - код взят из DLE почти без измененний
			if( strpos( $tpl->copy_template, "[xfvalue_" ) !== false OR strpos( $tpl->copy_template, "[xfgiven_" ) !== false ) { $xfound = true; $xfields = xfieldsload();}
			else $xfound = false;
			$xfields = xfieldsload();
			$xfieldsdata = $xf_replace;
			if( $xfound ) 
			{
				foreach ( $xfields as $value ) 
				{
					$preg_safe_name = preg_quote( $value[0], "'" );

					if ( $value[6] AND !empty( $xfieldsdata[$value[0]] ) ) {
						$temp_array = explode( ",", $xfieldsdata[$value[0]] );
						$value3 = array();

						foreach ($temp_array as $value2) {

							$value2 = trim($value2);
							$value2 = str_replace("&#039;", "'", $value2);

							if( $config['allow_alt_url'] == "yes" ) $value3[] = "<a href=\"" . $config['http_home_url'] . "xfsearch/" . urlencode( $value2 ) . "/\">" . $value2 . "</a>";
							else $value3[] = "<a href=\"$PHP_SELF?do=xfsearch&amp;xf=" . urlencode( $value2 ) . "\">" . $value2 . "</a>";
						}

						$xfieldsdata[$value[0]] = implode(", ", $value3);

						unset($temp_array);
						unset($value2);
						unset($value3);

					}
			
					if( empty($xfieldsdata[$value[0]])) {
						$tpl->copy_template = preg_replace( "'\\[xfgiven_{$preg_safe_name}\\](.*?)\\[/xfgiven_{$preg_safe_name}\\]'is", "", $tpl->copy_template );
						$tpl->copy_template = str_replace( "[xfnotgiven_{$value[0]}]", "", $tpl->copy_template );
						$tpl->copy_template = str_replace( "[/xfnotgiven_{$value[0]}]", "", $tpl->copy_template );
					} else {
						$tpl->copy_template = preg_replace( "'\\[xfnotgiven_{$preg_safe_name}\\](.*?)\\[/xfnotgiven_{$preg_safe_name}\\]'is", "", $tpl->copy_template );
						$tpl->copy_template = str_replace( "[xfgiven_{$value[0]}]", "", $tpl->copy_template );
						$tpl->copy_template = str_replace( "[/xfgiven_{$value[0]}]", "", $tpl->copy_template );
					}
					
					$xfieldsdata[$value[0]] = stripslashes( $xfieldsdata[$value[0]] );
					$tpl->copy_template = str_replace( "[xfvalue_{$value[0]}]", $xfieldsdata[$value[0]], $tpl->copy_template );
				}
			}
			// Закончили обрабатывать допполя


			// Компилируем шаблон (что бы это не означало ;))
			$tpl->compile('blockPro');


			// Выводим результат
			return $tpl->result['blockPro'];
		}

		/*
		 * Метод выводит содержимое модуля в браузер
		 * @param $output - строка для вывода
		 */
		public function showOutput($output)
		{
			echo $output;
		}

	}//конец класса BlockPro
} 

	// Цепляем конфиг модуля
	$BlockProConfig = array(
		'template'		=> !empty($template)?$template:'blockpro/blockpro', 		// Название шаблона (без расширения)
		'prefix'		=> !empty($BpPrefix)?$BpPrefix:'news_', 					// Дефолтный префикс кеша
		'nocache'		=> !empty($nocache)?$nocache:false,							// Не использовать кеш
		'cacheLive'	    => !empty($cacheLive)?$cacheLive:false,					    // Время жизни кеша в минутах

		'startFrom'	    => !empty($startFrom)?$startFrom:'0',						// C какой новости начать вывод
		'limit'			=> !empty($limit)?$limit:'10',								// Количество новостей в блоке	

		'postId'		=> !empty($postId)?$postId:'',							    // ID новостей для вывода в блоке (через запятую)
		'notPostId'	    => !empty($notPostId)?$notPostId:'',					    // ID игнорируемых новостей (через запятую)

		'author'		=> !empty($author)?$author:'',								// Логины авторов, для показа их новостей в блоке (через запятую)
		'notAuthor'	    => !empty($notAuthor)?$notAuthor:'',						// Логины игнорируемых авторов (через запятую)

		'xfilter'		=> !empty($xfilter)?$xfilter:'',							// Имена дополнительных полей для фильтрации по ним новостей (через запятую)
		'notXfilter'	=> !empty($notXfilter)?$notXfilter:'',					    // Имена дополнительных полей для игнорирования показа (через запятую)

		'catId'		    => !empty($catId)?$catId:'',								// Категории для показа	(через запятую)
		'notCatId'	    => !empty($notCatId)?$notCatId:'',						    // Игнорируемые категории (через запятую)
		
		'noicon'		=> !empty($noicon)?$noicon:'noicon.png',					// Заглушка для иконок категорий
		
		'day'			=> !empty($day)?$day:false,									// Временной период для отбора новостей		
		'sort'			=> !empty($sort)?$sort:'top',								// Сортировка (top, date, comms, rating, views, title)
		'order'			=> !empty($order)?$order:'new',								// Направление сортировки
		
		
		'image'			=> !empty($image)?$image:'short_story',						// Откуда брать картинку (short_story, full_story или xfield)
		'remoteImages'	=> !empty($remoteImages)?$remoteImages:false,				// Показывать картинки с других сайтов (уменьшаться они не будут!)
		'noimage'		=> !empty($noimage)?$noimage:'noimage.png',					// Картинка-заглушка маленькая
		'noimageFull'	=> !empty($noimageFull)?$noimageFull:'noimage-full.png',	// Картинка-заглушка большая
		'imgSize'		=> !empty($imgSize)?$imgSize:false,						    // Размер уменьшенной копии картинки
		'resizeType'	=> !empty($resizeType)?$resizeType:'auto',				    // Опция уменьшения копии картинки (exact, portrait, landscape, auto, crop)
		'imgQuality'	=> !empty($imgQuality)?$imgQuality:'80',				    // Качество создаваемой уменьшенной копии (0-100)
		
		
		'textLimit'	    => !empty($textLimit)?$textLimit:false,					    // Ограничение количества символов
		'titleLimit'	=> !empty($titleLimit)?$titleLimit:false,					// Ограничение количества символов в заголовке
		'wordcut'		=> !empty($wordcut)?$wordcut:false,							// Жесткое ограничение кол-ва символов, без учета длины слов		
		
		'showstat'		=> !empty($showstat)?$showstat:false,						// Показывать время стату по блоку
		
		'related'		=> !empty($related)?$related:'',							// Включить режим вывода похожих новостей (по умолчанию нет)


	);
	
	// Создаем экземпляр класса для перелинковки и запускаем его главный метод
	//$BlockPro = new BlockPro($BlockProConfig); // В сингелтоне такое неьзя делать
	$BlockPro = BlockPro::getInstance();
	$BlockPro->runBlockPro($BlockProConfig);


	//Показываем статистику генерации блока, если требуется
	if($showstat) echo '<p style="color:red;">Время выполнения: <b>'. round((microtime(true) - $start), 6). '</b> c.</p>';
?>
