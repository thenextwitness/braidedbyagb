package com.braidedbyagb.admin.ui.screens.login

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.braidedbyagb.admin.data.model.AuthRequest
import com.braidedbyagb.admin.data.remote.ApiClient
import com.braidedbyagb.admin.ui.theme.Gold
import com.braidedbyagb.admin.ui.theme.Purple800
import kotlinx.coroutines.launch

@Composable
fun LoginScreen(onLoginSuccess: () -> Unit) {
    val scope = rememberCoroutineScope()
    var email    by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var loading  by remember { mutableStateOf(false) }
    var error    by remember { mutableStateOf<String?>(null) }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Brush.verticalGradient(listOf(Purple800, Purple800.copy(alpha = 0.85f)))),
        contentAlignment = Alignment.Center
    ) {
        Card(
            modifier  = Modifier.fillMaxWidth(0.9f).wrapContentHeight(),
            elevation = CardDefaults.cardElevation(8.dp)
        ) {
            Column(
                modifier            = Modifier.padding(28.dp),
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                Text("BraidedbyAGB", fontWeight = FontWeight.ExtraBold, fontSize = 22.sp, color = Purple800)
                Text("Admin Portal", fontSize = 13.sp, color = Gold, letterSpacing = 2.sp)

                Spacer(Modifier.height(28.dp))

                OutlinedTextField(
                    value        = email,
                    onValueChange = { email = it },
                    label        = { Text("Email") },
                    singleLine   = true,
                    modifier     = Modifier.fillMaxWidth(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email, imeAction = ImeAction.Next)
                )

                Spacer(Modifier.height(12.dp))

                OutlinedTextField(
                    value        = password,
                    onValueChange = { password = it },
                    label        = { Text("Password") },
                    singleLine   = true,
                    modifier     = Modifier.fillMaxWidth(),
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done),
                    keyboardActions = KeyboardActions(onDone = {
                        if (!loading) scope.launch { doLogin(email, password, { loading = it }, { error = it }, onLoginSuccess) }
                    })
                )

                if (error != null) {
                    Spacer(Modifier.height(8.dp))
                    Text(error!!, color = MaterialTheme.colorScheme.error, fontSize = 13.sp)
                }

                Spacer(Modifier.height(20.dp))

                Button(
                    onClick  = { scope.launch { doLogin(email, password, { loading = it }, { error = it }, onLoginSuccess) } },
                    enabled  = !loading && email.isNotBlank() && password.isNotBlank(),
                    modifier = Modifier.fillMaxWidth().height(48.dp)
                ) {
                    if (loading) CircularProgressIndicator(Modifier.size(20.dp), color = MaterialTheme.colorScheme.onPrimary, strokeWidth = 2.dp)
                    else Text("Sign In", fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

private suspend fun doLogin(
    email: String,
    password: String,
    setLoading: (Boolean) -> Unit,
    setError:   (String?) -> Unit,
    onSuccess:  () -> Unit
) {
    setLoading(true); setError(null)
    try {
        val res = ApiClient.api.login(AuthRequest(email.trim(), password))
        when {
            res.isSuccessful && res.body() != null -> {
                ApiClient.setToken(res.body()!!.token)
                ApiClient.setEmail(email.trim())
                onSuccess()
            }
            res.code() == 401 -> setError("Incorrect email or password")
            res.code() == 429 -> setError("Account locked after too many attempts. Try again in 15 minutes.")
            res.code() == 400 -> setError("Email and password are required")
            else -> setError("Server error (${res.code()}). Please try again.")
        }
    } catch (e: Exception) {
        setError("Connection failed — check your internet and try again.")
    } finally {
        setLoading(false)
    }
}
