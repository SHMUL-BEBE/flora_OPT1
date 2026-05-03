<?php
session_start();
require_once __DIR__ . '/../config/db.php';

// Выход из аккаунта
if (isset($_GET['logout']) && $_GET['logout'] == 1) {
    session_destroy();
    header('Content-Type: application/json');
    echo json_encode(array('success' => true));
    exit;
}

// Проверка - сотрудники и админы перенаправляются в профиль
if (isset($_SESSION['user_role']) && ($_SESSION['user_role'] == 'админ' || $_SESSION['user_role'] == 'сотрудник')) {
    header('Location: index.php?page=profile');
    exit;
}

// Добавление товара в корзину
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['add'])) {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(array('success' => false, 'message' => 'Необходимо авторизоваться'));
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = array();
    }
    
    $productId = $data['id'];
    if (isset($_SESSION['cart'][$productId])) {
        $_SESSION['cart'][$productId]['quantity']++;
    } else {
        $_SESSION['cart'][$productId] = array(
            'name' => $data['name'],
            'price' => $data['price'],
            'quantity' => 1
        );
    }
    
    echo json_encode(array('success' => true));
    exit;
}

// Изменение количества товара
if (isset($_GET['change_quantity'])) {
    header('Content-Type: application/json');
    $id = $_GET['change_quantity'];
    $action = $_GET['action'];
    
    if (isset($_SESSION['cart'][$id])) {
        if ($action == 'increase') {
            $_SESSION['cart'][$id]['quantity']++;
        } elseif ($action == 'decrease') {
            $_SESSION['cart'][$id]['quantity']--;
            if ($_SESSION['cart'][$id]['quantity'] <= 0) {
                unset($_SESSION['cart'][$id]);
            }
        }
    }
    echo json_encode(array('success' => true));
    exit;
}

// Удаление товара
if (isset($_GET['remove'])) {
    header('Content-Type: application/json');
    $id = $_GET['remove'];
    if (isset($_SESSION['cart'][$id])) {
        unset($_SESSION['cart'][$id]);
    }
    echo json_encode(array('success' => true));
    exit;
}

// Очистка корзины
if (isset($_GET['clear'])) {
    header('Content-Type: application/json');
    $_SESSION['cart'] = array();
    echo json_encode(array('success' => true));
    exit;
}

// Оформление заказа (POST запрос)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['create_order'])) {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(array('success' => false, 'message' => 'Необходимо авторизоваться'));
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $spos_opl = $data['payment_method'];
    $spos_dos = $data['delivery_method'];
    $add_dos = $data['delivery_address'];
    $opl_dos = ($spos_dos == 'Доставка' && isset($data['delivery_price'])) ? (int)$data['delivery_price'] : 0;
    
    // Получаем корзину
    $cart = isset($_SESSION['cart']) ? $_SESSION['cart'] : array();
    if (empty($cart)) {
        echo json_encode(array('success' => false, 'message' => 'Корзина пуста'));
        exit;
    }
    
    // Считаем сумму
    $summa = 0;
    foreach ($cart as $item) {
        $summa += $item['price'] * $item['quantity'];
    }
    $summa += $opl_dos;
    
    try {
        $pdo->beginTransaction();
        
        // Получаем первого сотрудника для привязки (или NULL)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE status = 'сотрудник' LIMIT 1");
        $stmt->execute();
        $user = $stmt->fetch();
        $id_users = $user ? $user['id'] : 1;
        
        // Создаем заказ
        $stmt = $pdo->prepare("
            INSERT INTO zakaz (id_klient, id_users, spos_opl, spos_dos, add_dos, opl_dos, summa, status, date) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'новый', NOW())
        ");
        $stmt->execute(array(
            $_SESSION['user_id'],
            $id_users,
            $spos_opl,
            $spos_dos,
            $add_dos,
            $opl_dos,
            $summa
        ));
        
        $orderId = $pdo->lastInsertId();
        
        // Добавляем товары в заказ
        $stmt = $pdo->prepare("
            INSERT INTO zakaz_items (id_zakaz, id_assorti, quantity, price_at_moment) 
            VALUES (?, ?, ?, ?)
        ");
        
        foreach ($cart as $productId => $item) {
            $stmt->execute(array($orderId, $productId, $item['quantity'], $item['price']));
            
            // Обновляем количество товара на складе
            $updateStmt = $pdo->prepare("UPDATE assorti SET kolvo = kolvo - ? WHERE id = ? AND kolvo >= ?");
            $updateStmt->execute(array($item['quantity'], $productId, $item['quantity']));
        }
        
        $pdo->commit();
        
        // Очищаем корзину
        $_SESSION['cart'] = array();
        
        echo json_encode(array('success' => true, 'order_id' => $orderId));
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(array('success' => false, 'message' => 'Ошибка при оформлении заказа: ' . $e->getMessage()));
    }
    exit;
}

// Если не авторизован - показываем форму входа
if (!isset($_SESSION['user_id'])) {
    ?>
    <div class="cart-container">
        <h2 class="cart-title">Корзина</h2>
        <div class="empty-cart">
            <div class="empty-cart-title">Для работы с корзиной необходимо авторизоваться</div>
            <button class="btn1" onclick="window.location.href='index.php?page=auth'">Войти</button>
            <div class="empty-cart-text">Нет аккаунта? <a href="index.php?page=auth&tab=register" class="cart-link">Зарегистрируйтесь</a></div>
        </div>
    </div>
    <?php
    exit;
}

$userName = $_SESSION['user_name'];
$userEmail = $_SESSION['user_email'];
$userPhone = isset($_SESSION['user_phone']) ? $_SESSION['user_phone'] : '';
$cart = isset($_SESSION['cart']) ? $_SESSION['cart'] : array();
$total = 0;
foreach ($cart as $item) {
    $total += $item['price'] * $item['quantity'];
}

// Получаем телефон пользователя из БД, если его нет в сессии
if (empty($userPhone) && isset($_SESSION['user_id']) && $_SESSION['user_type'] == 'klient') {
    try {
        $stmt = $pdo->prepare("SELECT phone FROM klient WHERE id = ?");
        $stmt->execute(array($_SESSION['user_id']));
        $user = $stmt->fetch();
        if ($user) {
            $userPhone = $user['phone'];
            $_SESSION['user_phone'] = $userPhone;
        }
    } catch (PDOException $e) {}
}
?>

<div class="cart-page">
    <h2 class="cart-title">Корзина</h2>
    
    <?php if (empty($cart)): ?>
        <div class="empty-cart">
            <div class="empty-cart-title">Корзина пуста</div>
            <div class="empty-cart-text">Добавьте товары из каталога</div>
            <button class="btn1" onclick="window.location.href='index.php'">Перейти в каталог</button>
        </div>
    <?php else: ?>
        <div class="cart-items">
            <?php foreach ($cart as $id => $item): 
                $itemTotal = $item['price'] * $item['quantity'];
            ?>
                <div class="cart-item" data-id="<?php echo $id; ?>">
                    <div class="cart-item-info">
                        <div class="cart-item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                        <div class="cart-item-price"><?php echo number_format($item['price'], 0, ',', ' '); ?> ₽ · шт</div>
                        <div class="cart-item-stock">ассортимент · в наличии</div>
                    </div>
                    <div class="cart-item-actions">
                        <div class="quantity-control">
                            <button class="quantity-btn" onclick="changeQuantity(<?php echo $id; ?>, 'decrease')">−</button>
                            <span class="quantity-value" id="qty-<?php echo $id; ?>"><?php echo $item['quantity']; ?></span>
                            <button class="quantity-btn" onclick="changeQuantity(<?php echo $id; ?>, 'increase')">+</button>
                        </div>
                        <div class="cart-item-total">
                            <div class="cart-item-total-price" id="total-<?php echo $id; ?>">
                                <?php echo number_format($itemTotal, 0, ',', ' '); ?> ₽
                            </div>
                            <button class="remove-item" onclick="removeFromCart(<?php echo $id; ?>)">Удалить</button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Форма оформления заказа -->
        <div class="order-form-section" style="background: #fff9f0; border-radius: 20px; padding: 25px 30px; margin-bottom: 30px; border: 1px solid #e6d8c1;">
            <h3 style="font-family: Extra-Bold; color: #3d2b1f; margin-bottom: 20px; font-size: 20px;">Детали заказа</h3>
            
            <div class="form-group" style="margin-bottom: 20px;">
                <label style="display: block; font-family: Semi-Bold; color: #3d2b1f; margin-bottom: 8px;">Способ оплаты</label>
                <select id="paymentMethod" style="width: 100%; padding: 12px; border: 2px solid #e6d8c1; border-radius: 16px; font-family: Semi-Bold; background: white;">
                    <option value="Наличные">Наличные при получении</option>
                    <option value="Безналичные">Безналичный расчет (карта)</option>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 20px;">
                <label style="display: block; font-family: Semi-Bold; color: #3d2b1f; margin-bottom: 8px;">Способ доставки</label>
                <select id="deliveryMethod" onchange="toggleDeliveryAddress()" style="width: 100%; padding: 12px; border: 2px solid #e6d8c1; border-radius: 16px; font-family: Semi-Bold; background: white;">
                    <option value="Самовывоз">Самовывоз (бесплатно)</option>
                    <option value="Доставка">Доставка (от 300 ₽)</option>
                </select>
            </div>
            
            <div class="form-group" id="addressGroup" style="margin-bottom: 20px; display: none;">
                <label style="display: block; font-family: Semi-Bold; color: #3d2b1f; margin-bottom: 8px;">Адрес доставки</label>
                <input type="text" id="deliveryAddress" placeholder="Город, улица, дом, квартира" style="width: 100%; padding: 12px; border: 2px solid #e6d8c1; border-radius: 16px; font-family: Semi-Bold;">
                <small style="display: block; margin-top: 8px; color: #8e9680;">Доставка бесплатно от 3000 ₽ в пределах города. За городом +30-50 ₽/км.</small>
            </div>
            
            <div class="form-group" id="deliveryPriceGroup" style="margin-bottom: 20px; display: none;">
                <label style="display: block; font-family: Semi-Bold; color: #3d2b1f; margin-bottom: 8px;">Стоимость доставки (₽)</label>
                <input type="number" id="deliveryPrice" value="0" onchange="updateTotal()" style="width: 100%; padding: 12px; border: 2px solid #e6d8c1; border-radius: 16px; font-family: Semi-Bold;">
            </div>
        </div>
        
        <div class="cart-summary">
            <div class="cart-total-label">Итого товаров:</div>
            <div class="cart-total-amount" id="cart-subtotal"><?php echo number_format($total, 0, ',', ' '); ?> ₽</div>
        </div>
        
        <div class="cart-summary" id="deliverySummary" style="display: none;">
            <div class="cart-total-label">Доставка:</div>
            <div class="cart-total-amount" id="deliveryAmount">0 ₽</div>
        </div>
        
        <div class="cart-summary" style="background: #e6d8c1;">
            <div class="cart-total-label">Общая сумма:</div>
            <div class="cart-total-amount" id="cart-total"><?php echo number_format($total, 0, ',', ' '); ?> ₽</div>
        </div>
        
        <div class="cart-buttons">
            <button class="btn1" onclick="clearCart()">Очистить корзину</button>
            <button class="btn2" id="orderBtn" onclick="createOrder()">Оформить заказ</button>
        </div>
    <?php endif; ?>
</div>

<script>
let deliveryPrice = 0;
let subtotal = <?php echo $total; ?>;

function toggleDeliveryAddress() {
    var deliveryMethod = document.getElementById('deliveryMethod').value;
    var addressGroup = document.getElementById('addressGroup');
    var deliveryPriceGroup = document.getElementById('deliveryPriceGroup');
    var deliverySummary = document.getElementById('deliverySummary');
    
    if (deliveryMethod === 'Доставка') {
        addressGroup.style.display = 'block';
        deliveryPriceGroup.style.display = 'block';
        deliverySummary.style.display = 'flex';
        document.getElementById('deliveryAddress').required = true;
    } else {
        addressGroup.style.display = 'none';
        deliveryPriceGroup.style.display = 'none';
        deliverySummary.style.display = 'none';
        deliveryPrice = 0;
        document.getElementById('deliveryAddress').required = false;
        updateTotal();
    }
}

function updateTotal() {
    var deliveryInput = document.getElementById('deliveryPrice');
    if (deliveryInput) {
        deliveryPrice = parseInt(deliveryInput.value) || 0;
    }
    var total = subtotal + deliveryPrice;
    document.getElementById('cart-total').innerHTML = total.toLocaleString('ru-RU') + ' ₽';
    document.getElementById('deliveryAmount').innerHTML = deliveryPrice.toLocaleString('ru-RU') + ' ₽';
}

function changeQuantity(id, action) {
    fetch('components/trash.php?change_quantity=' + id + '&action=' + action)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                window.location.href = 'index.php?page=trash';
            }
        })
        .catch(function(error) { console.error('Ошибка:', error); });
}

function removeFromCart(id) {
    if (confirm('Удалить товар из корзины?')) {
        fetch('components/trash.php?remove=' + id)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    window.location.href = 'index.php?page=trash';
                }
            })
            .catch(function(error) { console.error('Ошибка:', error); });
    }
}

function clearCart() {
    if (confirm('Очистить всю корзину?')) {
        fetch('components/trash.php?clear=1')
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    window.location.href = 'index.php?page=trash';
                }
            })
            .catch(function(error) { console.error('Ошибка:', error); });
    }
}

function createOrder() {
    var deliveryMethod = document.getElementById('deliveryMethod').value;
    var paymentMethod = document.getElementById('paymentMethod').value;
    var deliveryAddress = document.getElementById('deliveryAddress') ? document.getElementById('deliveryAddress').value : '';
    var deliveryPriceVal = document.getElementById('deliveryPrice') ? parseInt(document.getElementById('deliveryPrice').value) || 0 : 0;
    
    if (deliveryMethod === 'Доставка' && !deliveryAddress.trim()) {
        alert('Пожалуйста, укажите адрес доставки');
        return;
    }
    
    var orderBtn = document.getElementById('orderBtn');
    orderBtn.disabled = true;
    orderBtn.textContent = 'Оформление...';
    
    fetch('components/trash.php?create_order=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            payment_method: paymentMethod,
            delivery_method: deliveryMethod,
            delivery_address: deliveryAddress || '-',
            delivery_price: deliveryPriceVal
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            alert('Заказ №' + data.order_id + ' успешно оформлен! Спасибо за покупку!');
            window.location.href = 'index.php';
        } else {
            alert('Ошибка: ' + (data.message || 'Не удалось оформить заказ'));
            orderBtn.disabled = false;
            orderBtn.textContent = 'Оформить заказ';
        }
    })
    .catch(function(error) {
        console.error('Ошибка:', error);
        alert('Произошла ошибка при оформлении заказа');
        orderBtn.disabled = false;
        orderBtn.textContent = 'Оформить заказ';
    });
}

function logout() {
    if (confirm('Вы уверены, что хотите выйти?')) {
        fetch('components/trash.php?logout=1')
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    window.location.href = 'index.php';
                }
            })
            .catch(function(error) {
                console.error('Ошибка:', error);
                window.location.href = 'index.php';
            });
    }
}
</script>
